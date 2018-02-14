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
class CollectGarbage extends Command
{

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('gc')
            ->setDescription('Collects garbage (out of date) Kubernetes QA builds, takes days old as an argument')
            ->addArgument('days', InputArgument::REQUIRED)
            ->addArgument('kubeconfig', InputArgument::REQUIRED);
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {


        function startsWith($haystack, $needle)
        {
             $length = strlen($needle);
             return (substr($haystack, 0, $length) === $needle);
        }
        
        function endsWith($haystack, $needle)
        {
            $length = strlen($needle);
        
            return $length === 0 || 
            (substr($haystack, -$length) === $needle);
        }

        // Get arguments
        $days = $input->getArgument('days');
        $kubeconfig = $input->getArgument('kubeconfig');

        // Initialise the date from days passed
        $deleteBefore = strtotime("-$days days");

        if($deleteBefore === false) {
            throw new \RuntimeException("Invalid number of days passed to script, must be numeric");
        }

        // Initialise input/output
        $io = new SymfonyStyle($input, $output);

        // Initalise variables
        $qaNamespaces = array();
        $toBeDeleted = array();

        $io->title("Removing QA deployments and cron jobs from Kubernetes built before " . date("H:i d/m/Y", $deleteBefore));

        $io->section("Getting namespaces");

        // Get all namespaces
        unset($json);
        $command = 'kubectl get ns --kubeconfig=' . $kubeconfig . ' -o json';
        exec($command, $json, $commandStatus);
        if ($commandStatus != 0) {
            throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
        }

        $json = implode("\n", $json);
        $namespaces = json_decode($json);

        // Remove any namespaces that don't end in -qa
        foreach ($namespaces->items as $key => $namespace) {
            if(endsWith($namespace->metadata->name,'-qa')) {
                array_push($qaNamespaces, $namespace->metadata->name);
            }
        }

        $qaNamespaceCount = count($qaNamespaces);

        $table = new Table($output);
        $tableOutput = array();
        foreach ($qaNamespaces as $key => $qaNamespace) {
            array_push($tableOutput, array($qaNamespace));
        }
        $table
            ->setHeaders(array("Found $qaNamespaceCount namespaces to check for removable deployments and cron jobs:"))
            ->setRows($tableOutput);
        $table->render();

        $output->writeln("\n");

        // $qaNamespaces now contains a list of namespaces (by name) and $qaNamespacesCount contains a count of how many there are in total

        // Initialise the progress bar
        $progress = new ProgressBar($output, $qaNamespaceCount * 5);
        $progress->setProgressCharacter("\xf0\x9f\x94\x8e");
        $progress->setFormatDefinition('custom', '%current%/%max% [%bar%] : %message%');
        $progress->setFormat('custom');
        $progress->setOverwrite(false);
        $progress->setMessage('Scanning namespaces');
        $progress->start();

        foreach ($qaNamespaces as $key => $namespace) {

            // Deployments/replicasets
            unset($json);
            $replicaSetCount = 0;
            $cronCount = 0;
            $toBeDeletedCount = 0;

            $command = 'kubectl get rs --namespace=' . $namespace . ' --kubeconfig=' . $kubeconfig . ' -o json';
            exec($command, $json, $commandStatus);
            if ($commandStatus != 0) {
                throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
            }

            $json = implode("\n", $json);
            $replicaSets = json_decode($json);
            
            $replicaSetCount = count($replicaSets->items);

            // If this namespace contains replicasets, store some information about them so we can add them to the delete stack if necessary:
            if ($replicaSetCount > 0) {
                foreach ($replicaSets->items as $replicaSetKey => $replicaSet) {

                    $name = '';
                    $owner = '';
                    $ownerKind = '';
                    $creationTimestamp = '';
                    $replicas = 0;
                    $dateCreated = 9999999999;

                    $name = $replicaSet->metadata->name;
                    $owner = $replicaSet->metadata->ownerReferences[0]->name;
                    $ownerKind = $replicaSet->metadata->ownerReferences[0]->kind;
                    $creationTimestamp = $replicaSet->metadata->creationTimestamp;
                    $replicas = $replicaSet->status->replicas;

                    // Only push this on for further progressing if it has more than 0 replicas. Old replicasets (i.e. ones that have been replaced) have no replicas. Either way, we wouldn't care about removing a replicaset with no replicas anyway.
                    if ($replicas > 0) {

                        // Calculate the age of the replicaset
                        $dateCreated = strtotime($creationTimestamp);

                        // If date created is less than $deleteBefore then the replicaset has to go, as long as it's owned by a Deployment
                        if ($dateCreated < $deleteBefore && $ownerKind === 'Deployment') {
                            array_push($toBeDeleted, array(
                                'namespace' => $namespace,
                                'name' => $owner,
                                'creationTimestampHuman' => date("H:i d/m/Y", $dateCreated),
                                'kind' => 'Deployment'
                            ));
                            $toBeDeletedCount ++;
                        }
                    }
                }
            }

            $progress->setMessage('<info>' . $namespace . '</info> found ' . $toBeDeletedCount . ' items to be removed after scanning deployments');
            $progress->advance();

            // Cron
            unset($json);
            $command = 'kubectl get cronjobs --namespace=' . $namespace . ' --kubeconfig=' . $kubeconfig . ' -o json';
            exec($command, $json, $commandStatus);
            if ($commandStatus != 0) {
                throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
            }

            $json = implode("\n", $json);
            $cronJobs = json_decode($json);

            $cronJobsCount = count($cronJobs->items);

            if ($cronJobsCount > 0) {
                foreach ($cronJobs->items as $cronJobKey => $cronJob) {

                    // Only continue processing if this cronjob has a build timestamp
                    if (isset($cronJob->metadata->annotations->buildTimestamp)) {

                        // Check the build timestamp is earlier than our delete before
                        if ($cronJob->metadata->annotations->buildTimestamp < $deleteBefore && $cronJob->kind === 'CronJob') {
                            array_push($toBeDeleted, array(
                                'namespace' => $cronJob->metadata->namespace,
                                'name' => $cronJob->metadata->name,
                                'creationTimestampHuman' => date("H:i d/m/Y", $cronJob->metadata->annotations->buildTimestamp),
                                'kind' => 'Cron Job'
                            ));
                            $toBeDeletedCount ++;
                        }
                    }
                }
            }

            $progress->setMessage('<info>' . $namespace . '</info> found ' . $toBeDeletedCount . ' items to be removed after scanning cronjobs');
            $progress->advance();

            // Horizontal Pod Autoscalers
            unset($json);
            $command = 'kubectl get hpa --namespace=' . $namespace . ' --kubeconfig=' . $kubeconfig . ' -o json';
            exec($command, $json, $commandStatus);
            if ($commandStatus != 0) {
                throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
            }

            $json = implode("\n", $json);
            $horizontalPodAutoscalers = json_decode($json);

            $horizontalPodAutoscalersCount = count($horizontalPodAutoscalers->items);

            if ($horizontalPodAutoscalersCount > 0) {
                foreach ($horizontalPodAutoscalers->items as $horizontalPodAutoscalerKey => $horizontalPodAutoscaler) {

                    // Check for replicas - if there are none, continue
                    if ($horizontalPodAutoscaler->status->currentReplicas == 0) {

                        array_push($toBeDeleted, array(
                            'namespace' => $horizontalPodAutoscaler->metadata->namespace,
                            'name' => $horizontalPodAutoscaler->metadata->name,
                            'creationTimestampHuman' => date("H:i d/m/Y", strtotime($horizontalPodAutoscaler->metadata->creationTimestamp)),
                            'kind' => 'Horizontal Pod Autoscaler'
                        ));
                        $toBeDeletedCount ++;
                    }
                }
            }

            $progress->setMessage('<info>' . $namespace . '</info> found ' . $toBeDeletedCount . ' items to be removed after scanning horizontal pod autoscalers');
            $progress->advance();

            // Services
            unset($json);
            $command = 'kubectl get svc --namespace=' . $namespace . ' --kubeconfig=' . $kubeconfig . ' -o json';
            exec($command, $json, $commandStatus);
            if ($commandStatus != 0) {
                throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
            }

            $json = implode("\n", $json);
            $services = json_decode($json);

            $servicesCount = count($services->items);

            if ($servicesCount > 0) {
                foreach ($services->items as $serviceKey => $service) {

                    // Reset
                    unset($pods);
                    unset($json);
                    unset($podCount);

                    // If this selector is set
                    if (isset($service->spec->selector->app)) {

                        // Find any pods that match the selector
                        $command = 'kubectl get pods --namespace=' . $namespace . ' --selector app=' . $service->spec->selector->app . ' --kubeconfig=' . $kubeconfig . ' -o json';
                        exec($command, $json, $commandStatus);
                        if ($commandStatus != 0) {
                            throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
                        }
                        $json = implode("\n", $json);
                        $pods = json_decode($json);
                        $podCount = count($pods->items);

                        // If the pod count is zero, there are no selectable pods
                        if ($podCount === 0) {
                            array_push($toBeDeleted, array(
                                'namespace' => $service->metadata->namespace,
                                'name' => $service->metadata->name,
                                'creationTimestampHuman' => date("H:i d/m/Y", strtotime($service->metadata->creationTimestamp)),
                                'kind' => 'Service'
                            ));
                            $toBeDeletedCount ++;
                        }


                    }
                }
            }

            $progress->setMessage('<info>' . $namespace . '</info> found ' . $toBeDeletedCount . ' items to be removed after scanning services');
            $progress->advance();

            // Ingresses
            unset($json);
            $command = 'kubectl get ingress --namespace=' . $namespace . ' --kubeconfig=' . $kubeconfig . ' -o json';
            exec($command, $json, $commandStatus);
            if ($commandStatus != 0) {
                throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
            }

            $json = implode("\n", $json);
            $ingresses = json_decode($json);

            $ingressCount = count($ingresses->items);

            if ($ingressCount > 0) {
                foreach ($ingresses->items as $ingressKey => $ingress) {

                    unset($services);
                    $services = [];
                    // We have each ingress, now we need to quickly run through and dedupe each service that's referenced in the ingress backend paths to build a list to check against
                    foreach ($ingress->spec->rules as $ruleKey => $rule) {
                        foreach ($rule->http->paths as $pathKey => $path) {
                            if (isset($path->backend->serviceName)) {
                                array_push($services, $path->backend->serviceName);
                            }
                        }
                    }

                    // Deduplicate the array (likely to actually consist entirely of duplicates)
                    $services = array_unique($services);

                    // If there are any items in the array:
                    if (count($services) > 0) {

                        // Set a pointer
                        $delete = 1;

                        foreach ($services as $serviceKey => $service) {
                            // Query kubectl for the service to see if it actually exists
                            $command = 'kubectl get svc --namespace=' . $namespace . ' ' . $service . ' --kubeconfig=' . $kubeconfig . ' -o json 2>1';
                            exec($command, $json, $commandStatus);
                            if ($commandStatus != 0) {
                                // This doesn't actually mean that the command has failed failed - it's failed because there is no such service. If so, continue...
                            }
                            else {
                                // The service exists! Probably best to leave this ingress in-place.
                                $delete = 0;
                            }
                        }

                        // If $delete is still 1, then we can safely say that none of the services referenced actually exist, so it's safe to delete the ingress:
                        if ($delete === 1) {
                            array_push($toBeDeleted, array(
                                'namespace' => $ingress->metadata->namespace,
                                'name' => $ingress->metadata->name,
                                'creationTimestampHuman' => date("H:i d/m/Y", strtotime($ingress->metadata->creationTimestamp)),
                                'kind' => 'Ingress'
                            ));
                            $toBeDeletedCount ++;
                        }
                    }
                }
            }

            $progress->setMessage('<info>' . $namespace . '</info> found ' . $toBeDeletedCount . ' items to be removed after scanning ingresses');
            $progress->advance();

        }

        $progress->finish();

        $output->writeln("\n\n");

        $$toBeDeletedCount = 0;
        $toBeDeletedCount = count($toBeDeleted);

        if ($toBeDeletedCount > 0)
        {
            $io->section("Found the following $toBeDeletedCount deployments, ingresses, cron jobs, hpas and services to be removed");
            $table
                ->setHeaders(array('Namespace', 'Name', 'Created at', 'Kind'))
                ->setRows($toBeDeleted);
            $table->render();

            $output->writeln("\n");

            // Dry runs should exit here
            //exit;

            // Initialise the progress bar
            unset($progress);
            $progress = new ProgressBar($output, $toBeDeletedCount);
            $progress->setProgressCharacter("\xf0\x9f\x92\xa8");
            $progress->setFormatDefinition('custom', '%current%/%max% [%bar%] : %message%');
            $progress->setFormat('custom');
            $progress->setOverwrite(false);
            $progress->setMessage('Starting removal');
            $progress->start();

            foreach ($toBeDeleted as $deleteKey => $deleteRow) {

                if ($deleteRow['kind'] === 'Deployment') {
                    $command = 'kubectl delete deployment ' . $deleteRow['name'] . ' --namespace=' . $deleteRow['namespace'] . ' --kubeconfig=' . $kubeconfig;
                    exec($command, $json, $commandStatus);
                    if ($commandStatus != 0) {
                        throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
                    }
                }

                if ($deleteRow['kind'] === 'Cron Job') {
                    $command = 'kubectl delete cronjob ' . $deleteRow['name'] . ' --namespace=' . $deleteRow['namespace'] . ' --kubeconfig=' . $kubeconfig;
                    exec($command, $json, $commandStatus);
                    if ($commandStatus != 0) {
                        throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
                    }
                }

                if ($deleteRow['kind'] === 'Horizontal Pod Autoscaler') {
                    $command = 'kubectl delete hpa ' . $deleteRow['name'] . ' --namespace=' . $deleteRow['namespace'] . ' --kubeconfig=' . $kubeconfig;
                    exec($command, $json, $commandStatus);
                    if ($commandStatus != 0) {
                        throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
                    }
                }

                if ($deleteRow['kind'] === 'Service') {
                    $command = 'kubectl delete service ' . $deleteRow['name'] . ' --namespace=' . $deleteRow['namespace'] . ' --kubeconfig=' . $kubeconfig;
                    exec($command, $json, $commandStatus);
                    if ($commandStatus != 0) {
                        throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
                    }
                }

                if ($deleteRow['kind'] === 'Ingress') {
                    $command = 'kubectl delete ingress ' . $deleteRow['name'] . ' --namespace=' . $deleteRow['namespace'] . ' --kubeconfig=' . $kubeconfig;
                    exec($command, $json, $commandStatus);
                    if ($commandStatus != 0) {
                        throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
                    }
                }

                $progress->setMessage('Removed ' . $deleteRow['kind'] . ' ' . $deleteRow['name'] . ' from ' . $deleteRow['namespace']);
                $progress->advance();

            }
            $progress->finish();
            $output->writeln("\n");
            $io->section("Successfully deleted $toBeDeletedCount deployments and cron jobs");

        }
        else {
            $io->section("No removable deployments or cron jobs");
        }   
    }
}
