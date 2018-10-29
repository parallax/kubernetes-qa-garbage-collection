<?php

/*
 * This file is part of the Cilex framework.
 *
 * (c) Mike van Riel <mike.vanriel@naenius.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cilex\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Cilex\Provider\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

/**
 * Example command for testing purposes.
 */
class CheckEvictions extends Command
{

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('checkEvictions')
            ->setDescription('Checks for Kubernetes deployments that don\'t have cluster-autoscaler.kubernetes.io/safe-to-evict set to true (apart from those in the kube-system namespace)')
            ->addArgument('kubeconfig', InputArgument::REQUIRED);
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        // Get arguments
        $kubeconfig = $input->getArgument('kubeconfig');

        // Initialise input/output
        $io = new SymfonyStyle($input, $output);

        $missingEvictions = array();

        $output->writeln("Getting All Deployments");

        unset($json);
        $command = 'kubectl get deployments --all-namespaces --kubeconfig=' . $kubeconfig . ' -o json';
        //$command = 'kubectl get secrets -n rocol-laravel-qa --kubeconfig=' . $kubeconfig . ' -o json';
        exec($command, $json, $commandStatus);
        if ($commandStatus != 0) {
            throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
        }

        $json = implode("\n", $json);
        $deployments = json_decode($json);


        foreach ($deployments->items as $key => $deployment) {
            if ($deployment->metadata->namespace != 'kube-system' && $deployment->metadata->namespace != 'default' && $deployment->metadata->namespace != 'cloud-sql') {
                //echo $deployment->metadata->name;
                if (!isset($deployment->metadata->annotations->{'cluster-autoscaler.kubernetes.io/safe-to-evict'})) {
                    array_push($missingEvictions, array(
                        'name' => $deployment->metadata->name, 
                        'namespace' => $deployment->metadata->namespace, 
                    ));
                }
            }
        }

        $table = new Table($output);
        $tableOutput = array();
        $table
            ->setHeaders(array("Name", "Namespace"))
            ->setRows($missingEvictions);
        $table->render();

        

    }
}
