<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:database:backup',
    description: 'Create a SQL backup of the configured database in var/backups',
)]
class DatabaseBackupCommand extends Command
{
    public function __construct(
        #[Autowire('%env(resolve:DATABASE_URL)%')]
        private readonly string $databaseUrl,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $parts = parse_url($this->databaseUrl);

        if (!$parts || !isset($parts['host'], $parts['path'])) {
            $io->error('DATABASE_URL could not be parsed.');

            return Command::FAILURE;
        }

        $database = ltrim($parts['path'], '/');
        $user = $parts['user'] ?? 'root';
        $password = $parts['pass'] ?? '';
        $port = (string) ($parts['port'] ?? 3306);
        $backupDir = $this->projectDir . '/var/backups';
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            $io->error('Could not create backup directory.');

            return Command::FAILURE;
        }

        $target = $backupDir . '/' . $database . '-' . date('Ymd-His') . '.sql';
        $process = match ($scheme) {
            'postgres', 'postgresql' => new Process([
                'pg_dump',
                '--host=' . $parts['host'],
                '--port=' . $port,
                '--username=' . $user,
                '--format=plain',
                '--no-owner',
                '--no-privileges',
                '--file=' . $target,
                $database,
            ]),
            'mysql', 'mariadb' => new Process([
                'mysqldump',
                '--host=' . $parts['host'],
                '--port=' . $port,
                '--user=' . $user,
                $password !== '' ? '--password=' . $password : '--password=',
                '--databases',
                $database,
                '--result-file=' . $target,
            ]),
            default => null,
        };

        if (!$process instanceof Process) {
            $io->error('Unsupported database driver in DATABASE_URL. Use postgres/postgresql or mysql/mariadb.');

            return Command::FAILURE;
        }

        if ($scheme === 'postgres' || $scheme === 'postgresql') {
            $process->setEnv(array_merge($_ENV, $_SERVER, [
                'PGPASSWORD' => $password,
            ]));
        }

        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error($process->getErrorOutput() ?: $process->getOutput());

            return Command::FAILURE;
        }

        $io->success('Backup created: ' . $target);

        return Command::SUCCESS;
    }
}
