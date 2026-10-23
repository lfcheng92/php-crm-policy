<?php
declare(strict_types=1);
namespace Lfcheng\CrmPolicy\Tests\Integration;
use Lfcheng\CrmPolicy\Sqlite\Schema;
use Lfcheng\CrmPolicy\Tests\Support\Fixtures as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqliteBatchConcurrencyTest extends TestCase
{
    #[DataProvider('scenarios')]
    public function testWalCompetingOperationsAreAtomic(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/crm-batch-race-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700); $file = $directory . '/isolated.sqlite';
        $pdo = new \PDO('sqlite:' . $file); Schema::create($pdo);
        $pdo->exec('PRAGMA journal_mode=WAL');
        F::storeMember($pdo, F::member()); F::storeMember($pdo, F::member('bob'));
        F::storeMember($pdo, F::member('collector', limit: 1));
        if ($scenario === 'transfer last slot') {
            F::storeCustomer($pdo, F::customer('a', owner: 'alice')); F::storeCustomer($pdo, F::customer('b', owner: 'bob'));
            $commands = [
                ['actor'=>'alice','request'=>'r1','action'=>'transfer','ids'=>['a'],'target'=>'collector'],
                ['actor'=>'bob','request'=>'r2','action'=>'transfer','ids'=>['b'],'target'=>'collector'],
            ];
        } else {
            foreach (['a','b','c'] as $id) { F::storeCustomer($pdo, F::customer($id)); }
            $commands = $scenario === 'same batch request' ? [
                ['actor'=>'alice','request'=>'same','action'=>'claim','ids'=>['a','b','a']],
                ['actor'=>'alice','request'=>'same','action'=>'claim','ids'=>['b','a']],
            ] : [
                ['actor'=>'alice','request'=>'r1','action'=>'claim','ids'=>['a','b']],
                ['actor'=>'bob','request'=>'r2','action'=>'claim','ids'=>['b','c']],
            ];
        }
        $workers = []; $locked = false;
        try {
            foreach ($commands as $command) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/operation-worker.php', $file, json_encode($command, JSON_THROW_ON_ERROR)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
                self::assertIsResource($process); $workers[] = [$process,$pipes];
            }
            foreach ($workers as [, $pipes]) { self::assertSame('READY', $this->line($pipes[1])); }
            $pdo->exec('BEGIN IMMEDIATE'); $locked = true;
            foreach ($workers as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
            foreach ($workers as [, $pipes]) { self::assertSame('EXECUTING', $this->line($pipes[1])); }
            $pdo->exec('COMMIT'); $locked = false;
            $results = [];
            foreach ($workers as [$process,$pipes]) {
                $results[] = json_decode($this->line($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
                fclose($pipes[0]); fclose($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
            }
            $workers = [];
            $allowed = array_column($results, 'allowed'); sort($allowed);
            if ($scenario === 'same batch request') {
                self::assertSame([true,true], $allowed);
                $replays=array_column($results,'replayed');sort($replays);self::assertSame([false,true],$replays);
                self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM crm_requests')->fetchColumn());
            } else {
                self::assertSame([false,true],$allowed);
                self::assertContains($scenario === 'transfer last slot' ? 'capacity_exceeded' : 'already_owned',array_column($results,'reason'));
                self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM crm_requests')->fetchColumn());
            }
            self::assertSame($scenario === 'transfer last slot' ? 1 : 2,(int)$pdo->query('SELECT SUM(version) FROM crm_customers')->fetchColumn());
            self::assertSame(2,(int)$pdo->query('SELECT COUNT(*) FROM crm_customers WHERE owner_id IS NOT NULL')->fetchColumn());
            if ($scenario === 'transfer last slot') {
                self::assertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM crm_customers WHERE owner_id='collector'")->fetchColumn());
            }
        } finally {
            if ($locked) { $pdo->exec('ROLLBACK'); }
            foreach ($workers as [$process,$pipes]) {
                if(is_resource($process)){proc_terminate($process);}
                foreach($pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}
                if(is_resource($process)){proc_close($process);}
            }
            $pdo=null;
            foreach(glob($directory.'/*') ?: [] as $path){unlink($path);}rmdir($directory);
        }
    }
    public static function scenarios(): iterable
    {
        yield ['overlapping batches']; yield ['same batch request']; yield ['transfer last slot'];
    }
    private function line($stream): string
    {
        $read=[$stream];$write=null;$except=null;
        if(stream_select($read,$write,$except,10)!==1){throw new \RuntimeException('Worker timed out.');}
        $line=fgets($stream);if($line===false){throw new \RuntimeException('Worker exited early.');}return trim($line);
    }
}
