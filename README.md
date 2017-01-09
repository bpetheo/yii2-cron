# yii2-cron
Cron implementation for yii2 framework. Executes scheduled commands defined in yii config.
 So if you need to add/remove or reschedule a cron job you only need to modify and deploy the config file, no fiddling with crontab or other actions required. Handy if you are deploying many apps and/or deploy to multiple servers.
 Only requires server side scripts installed once and it is ready to use. The server side scripts can handle multiple projects (including automatic discovery of added/removed repos). They can be found here: https://github.com/bpetheo/autocron-server
 
 You can also assign tags to jobs, so if you use the same config file on multiple servers you can still have different cron jobs running (or the same jobs with different timings)
 
 The component does some basic logging to runtime/logs/{command}.{action}.{date}.log
 
## Installation

Get via composer:
`composer require "bpetheo/yii2-cron: ~1.0.0"`

Add to the controller map in config/console.php:
```
'controllerMap' => [
    // ...
    'cron' => [
        'class' => 'bpetheo\yii2cron\CronController',
        'defaultAction' => 'run',
    ],
],
```

## Configuration
When setting up timing values you can use the standard cron syntax elements (`*`, `*/5`, `15`, `0,30`). The command should be the command/action arguments of ./yii. For example if you would set `/path_to_project/yii hello/index` in the standard cron tab the command in config you want to use will be `hello/index`.

Full configuration block looks like this:
```
'params' => [
        // other parameters...
        'cronTab' => [
            // run hello/index command every 5 minutes on 21th septermber and
            // 21th december in default and dev environment
            'Sample cronjob - full config' => [
                'enabled' => true,
                'command'=>'hello/index',
                'timing' => [
                    'min' => '*/5',
                    'hour' => '*',
                    'day' => '21',
                    'month' => '9,12',
                    'dayofweek' => '*',
                ],
                'tags' => [
                    'default',
                    'development',
                ],
            ],
        // other parameters...
    ],
```

You can also use a simplified format by setting only the parameters differing from defaults:
```
'params' => [
        // other parameters...
        'cronTab' => [
            // execute hello/index every 30 minuted
            // short form, only non-default values are set explicitly
            'Sample cronjob - short config' => [
                'enabled' => true,
                'command'=>'hello',
                'timing' => [
                    'min' => '0,30',
                ],
            ],
        ],
        // other parameters...
    ],
```

## Contributing
I've made this project to fit my own needs. You might have different use cases which is not covered, but feel free to extend or modify the code to make it suitable to more people.