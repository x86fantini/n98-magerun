<?php

namespace N98\Magento\Command\System\Cron;

use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Exception\InvalidArgumentException;

class RunCommand extends AbstractCronCommand
{
    const REGEX_RUN_MODEL = '#^([a-z0-9_]+/[a-z0-9_]+)::([a-z0-9_]+)$#i';
    /**
     * @var array
     */
    protected $infos;

    protected function configure()
    {
        $this
            ->setName('sys:cron:run')
            ->addArgument('job', InputArgument::OPTIONAL, 'Job code')
            ->setDescription('Runs a cronjob by job code');
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output, true);
        if ($this->initMagento()) {

            $jobCode = $input->getArgument('job');
            if (!$jobCode) {
                $this->writeSection($output, 'Cronjob');
                $jobCode = $this->askJobCode($input, $output, $this->getJobs());
            }

            $jobsRoot = \Mage::getConfig()->getNode('crontab/jobs');
            $defaultJobsRoot = \Mage::getConfig()->getNode('default/crontab/jobs');

            $jobConfig = $jobsRoot->{$jobCode};
            if (!$jobConfig || !$jobConfig->run) {
                $jobConfig = $defaultJobsRoot->{$jobCode};
                if (!$jobConfig || !$jobConfig->run) {
                    throw new \Exception('No job config found!');
                }
            }

            $runConfig = $jobConfig->run;

            if ($runConfig->model) {

                if (!preg_match(self::REGEX_RUN_MODEL, (string)$runConfig->model, $run)) {
                    throw new \Exception('Invalid model/method definition, expecting "model/class::method".');
                }
                if (!($model = \Mage::getModel($run[1])) || !method_exists($model, $run[2])) {
                    throw new \Exception('Invalid callback: %s::%s does not exist', $run[1], $run[2]);
                }
                $callback = array($model, $run[2]);

                $output->write('<info>Run </info><comment>' . get_class($model) . '::' . $run[2] . '</comment> ');

                try {
                    $schedule = \Mage::getModel('cron/schedule');
                    $schedule
                        ->setJobCode($jobCode)
                        ->setStatus(\Mage_Cron_Model_Schedule::STATUS_RUNNING)
                        ->setExecutedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
                        ->save();

                    call_user_func_array($callback, array($schedule));

                    $schedule
                        ->setStatus(\Mage_Cron_Model_Schedule::STATUS_SUCCESS)
                        ->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
                        ->save();
                } catch (Exception $e) {
                    $schedule
                        ->setStatus(\Mage_Cron_Model_Schedule::STATUS_ERROR)
                        ->setMessages($e->getMessage())
                        ->setFinishedAt(strftime('%Y-%m-%d %H:%M:%S', time()))
                        ->save();
                }

                $output->writeln('<info>done</info>');
            }
            if (empty($callback)) {
                Mage::throwException(Mage::helper('cron')->__('No callbacks found'));
            }
        }
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param array $jobs
     * @return mixed
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    protected function askJobCode(InputInterface $input, OutputInterface $output, $jobs)
    {
        $i = 1;
        foreach ($jobs as $job) {
            $question[] = '<comment>[' . ($i++) . ']</comment> ' . $job['Job'] . PHP_EOL;
        }
        $question[] = '<question>Please select job: </question>' . PHP_EOL;

        $jobCode = $this->getHelperSet()->get('dialog')->askAndValidate(
            $output,
            $question,
            function ($typeInput) use ($jobs) {
                $subArray = array_slice($jobs, $typeInput - 1, 1);
                $firstElement = current($subArray);
                if (!$firstElement) {
                    throw new InvalidArgumentException('Invalid job');
                }

                return $firstElement['Job'];
            }
        );

        return $jobCode;
    }

}