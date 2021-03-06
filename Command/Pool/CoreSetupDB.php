<?php
/**
 * @author    Ewave <https://ewave.com/>
 * @copyright 2018-2019 NASKO TRADING PTY LTD
 * @license   https://ewave.com/wp-content/uploads/2018/07/eWave-End-User-License-Agreement.pdf BSD Licence
 */

namespace CoreDevBoxScripts\Command\Pool;

use CoreDevBoxScripts\Command\CommandAbstract;
use CoreDevBoxScripts\Command\Options\Db as DbOptions;
use CoreDevBoxScripts\Framework\Container;
use CoreDevBoxScripts\Framework\Downloader\DownloaderFactory;
use CoreDevBoxScripts\Library\JsonConfig;
use CoreDevBoxScripts\Library\EnvConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Command for Core final steps
 */
class CoreSetupDb extends CommandAbstract
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('core:setup:db')
            ->setDescription('Fetch / Update Database')
            ->setHelp('Update DB');

        $this->questionOnRepeat = 'Try to update Database again?';

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->executeRepeatedly('updateDatabase', $input, $output);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @throws \Exception
     * @return bool
     */
    protected function updateDatabase(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $this->commandTitle($io, 'Database sync.');

        $updateAgr = $this->requestOption(DbOptions::START, $input, $output, true);
        if (!$updateAgr) {
            $output->writeln('<comment>DB updating skipped</comment>');
            return true;
        }

        $sourceType = JsonConfig::getConfig('sources->db->source_type');
        $source = JsonConfig::getConfig('sources->db->source_path');
        $localDumpsStorage = JsonConfig::getConfig('sources->db->local_temp_path');
        $downloadOptions = JsonConfig::getConfig('sources->db');

        $coreHost = EnvConfig::getValue('WEBSITE_HOST_NAME');
        $projectName = EnvConfig::getValue('PROJECT_NAME');

        $mysqlHost = EnvConfig::getValue('CONTAINER_MYSQL_NAME');
        $mysqlHost = $projectName . '_' . $mysqlHost;
        $dbName = EnvConfig::getValue('CONTAINER_MYSQL_DB_NAME');
        $dbUser = 'root';
        $dbPassword = EnvConfig::getValue('CONTAINER_MYSQL_ROOT_PASS');

        if (!$mysqlHost || !$dbName || !$dbPassword) {
            $output->writeln('<comment>Some of required data are missed</comment>');
            $output->writeln('<comment>Reply on:</comment>');

            $mysqlHost = $input->getOption(DbOptions::HOST);
            $dbUser = $input->getOption(DbOptions::USER);
            $dbPassword = $input->getOption(DbOptions::PASSWORD);
            $dbName = $input->getOption(DbOptions::NAME);
        }

        $headers = ['Parameter', 'Value'];
        $rows = [
            ['Source', $source],
            ['Project URL', $coreHost],
            ['DB Host', $mysqlHost],
            ['DB Name', $dbName],
            ['DB User', $dbUser],
            ['DB Password', $dbPassword],
            ['DB dumps temp folder', $localDumpsStorage]
        ];
        $io->table($headers, $rows);

        if (!trim($source)) {
            throw new \Exception('Source path is not set in .env file. Recheck DATABASE_SOURCE_PATH parameter');
        }

        $isLocalFile = false;
        if (filter_var($source, FILTER_VALIDATE_URL) === false) {
            $isLocalFile = true;
        }

        $command = "mkdir -p " . $localDumpsStorage;
        $this->executeCommands(
            $command,
            $output
        );

        if (!$isLocalFile) {
            $fileFullPath = $localDumpsStorage . DIRECTORY_SEPARATOR . basename($source);

            /** @var DownloaderFactory $downloaderFactory */
            $downloaderFactory = Container::getContainer()->get(DownloaderFactory::class);

            try {
                $downloader = $downloaderFactory->get($sourceType);
                $downloader->download($source, $fileFullPath, $downloadOptions, $output);
                $io->success('Download completed');
            } catch (\Exception $e) {
                $io->warning([$e->getMessage()]);
                $io->warning('Some issues appeared during DB downloading.');
                return false;
            }
        } else {
            $fileFullPath = $source;
        }

        try {
            $newDumpPath = $this->unGz($fileFullPath, $output);
            if (!is_file($newDumpPath)) {
                throw new \Exception('File is not exists. Path: ' . $newDumpPath);
            }
        } catch (\Exception $e) {
            $io->note($e->getMessage());
            return false;
        }

        $output->writeln('<info>Extracting Database ...</info>');
        try {
            $this->executeCommands(
                "mysql -u$dbUser -p$dbPassword -h$mysqlHost $dbName < $newDumpPath",
                $output
            );
        } catch (\Exception $e) {
            $io->note($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getOptionsConfig()
    {
        return [
            DbOptions::START => DbOptions::get(DbOptions::START),
            DbOptions::HOST => DbOptions::get(DbOptions::HOST),
            DbOptions::USER => DbOptions::get(DbOptions::USER),
            DbOptions::PASSWORD => DbOptions::get(DbOptions::PASSWORD),
            DbOptions::NAME => DbOptions::get(DbOptions::NAME),
        ];
    }
}
