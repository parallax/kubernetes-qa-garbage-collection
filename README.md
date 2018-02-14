Kubernetes Garbage Collection
================================================

This tool removes old QA deployments, horizontal pod autoscalers, ingresses, services and cron jobs created using Parallax's prlx-deploy tooling.

Usage: bin/run.php gc 14 /path/to/kube/config

Where 14 is the amount of days to retain qa stuff

If you want to try a dry run (i.e. see what's going to be deleted without it actually deleting anything), uncomment the exit on line 395 of src/Cilex/Command/CollectGarbage.php

This tool will only look at items in namespaces that end in -qa