<?php

namespace JMose\CommandSchedulerBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Class MonitorCommand : This class is used for monitoring scheduled commands if they run for too long or failed to execute
 *
 * @author  Daniel Fischer <dfischer000@gmail.com>
 * @package JMose\CommandSchedulerBundle\Command
 */
class MonitorCommand extends ContainerAwareCommand
{

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $em;

    /**
     * @var boolean
     */
    private $dumpMode;

    /**
     * @var integer|boolean Number of seconds after a command is considered as timeout
     */
    private $lockTimeout;

    /**
     * @var string|array receiver for statusmail if an error occured
     */
    private $receiver;

    /**
     * @var boolean if true, current command will send mail even if all is ok.
     */
    private $sendMailIfNoError;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('scheduler:monitor')
            ->setDescription('Monitor scheduled commands')
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Display result instead of send mail')
            ->setHelp('This class is for monitoring all active commands.');
    }

    /**
     * Initialize parameters and services used in execute function
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->lockTimeout = $this->getContainer()->getParameter('jmose_command_scheduler.lock_timeout');
        $this->dumpMode = $input->getOption('dump');
        $this->receiver = $this->getContainer()->getParameter('jmose_command_scheduler.monitor_mail');
        $this->sendMailIfNoError = $this->getContainer()->getParameter('jmose_command_scheduler.send_ok');

        $this->em = $this->getContainer()->get('doctrine')->getManager(
            $this->getContainer()->getParameter('jmose_command_scheduler.doctrine_manager')
        );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // If not in dump mode and none receiver is set, exit.
        if (!$this->dumpMode && count($this->receiver) === 0) {
            $output->writeln('Please add receiver in configuration');

            return;
        }

        // Fist, get all failed or potential timeout
        $failedCommands = $this->em->getRepository('JMoseCommandSchedulerBundle:ScheduledCommand')
            ->findFailedAndTimeoutCommands($this->lockTimeout);

        // Commands in error
        if (count($failedCommands) > 0) {
            $message = "";

            foreach ($failedCommands as $command) {
                $message .= sprintf("%s: returncode %s, locked: %s, last execution: %s\n",
                    $command->getName(),
                    $command->getLastReturnCode(),
                    $command->getLocked(),
                    $command->getLastExecution()->format('Y-m-d H:i')
                );
            }

            // if --dump option, don't send mail
            if ($this->dumpMode) {
                $output->writeln($this->dumpMode);
            } else {
                $this->sendMails($message);
            }

        } else {
            if ($this->dumpMode) {
                $output->writeln('No errors found.');
            } elseif ($this->sendMailIfNoError) {
                $this->sendMails('No errors found.');
            }
        }
    }

    /**
     * send message to email receivers
     *
     * @param string $message message to be sent
     */
    private function sendMails($message)
    {
        // prepare email constants
        $hostname = gethostname();
        $subject = "cronjob monitoring " . $hostname . ", " . date('Y-m-d H:i:s');
        $headers = 'From: cron-monitor@' . $hostname . "\r\n" .
            'X-Mailer: PHP/' . phpversion();

        foreach ($this->receiver as $rcv) {
            mail(trim($rcv), $subject, $message, $headers);
        }
    }
}
