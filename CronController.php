<?php

namespace bpetheo\yii2cron;

use Yii;
use yii\base\InvalidParamException;
use yii\console\Controller;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;


class CronController extends Controller
{

    /**
     * @var string PHP interpriter path (if empty, path will be checked automaticly)
     */
    public $interpreterPath = null;

    /**
     * @var string path for category for logging
     */
    public $logsCategory = 'yii2-cron';

    /**
     * Update or rewrite log file
     * False - rewrite True - update(add to end logs)
     * @var bool
     */
    public $updateLogFile = false;

    /**
     * Placeholders:
     *     %R - Yii runtime path
     *     %T - cronjob title
     *     %C - cronjob command
     *     %P - pid of runner-script (current)
     *     %D(string formatted as arg of date() function) - formatted date
     * @var string mask log file name
     */
    public $logFile = '%R/cron/%C.%D(Y-m-d).log';
    /**
     * @var string Bootstrap script path (if empty, current command runner will be used)
     */
    public $bootstrapScript = null;
    /**
     * @var string Timestamp used as current datetime
     * @see http://php.net/manual/en/function.strtotime.php
     */
    public $timestamp = 'now';
    /**
     * @var string the name of the default action. Defaults to 'run'.
     */
    public $defaultAction = 'run';

    protected $defaultConfig = [
        'timing' => [
            'min' => '*',
            'hour' => '*',
            'day' => '*',
            'month' => '*',
            'dayofweek' => '*',
        ],
        'tags' => [
            'default'
        ],
        'superAdminIntegration' => true,
        'enabled' => true,
    ];

    /**
     * Initialize empty config parameters.
     */
    public function init()
    {
        parent::init();
        //Checking PHP interpriter path
        if ($this->interpreterPath === null) {
            //nix based OS
            $this->interpreterPath = '/usr/bin/env php';
        }
        //Checking bootstrap script
        if ($this->bootstrapScript === null) {
            $this->bootstrapScript = Yii::getAlias('@app/yii');
        }
    }

    /**
     * Provides the command description.
     * @return string the command description.
     */
    public function getHelp()
    {
        $commandUsage = Yii::getAlias('@app/yii') . ' ' . $this->id;
        return <<<RAW
Usage: {$commandUsage} <action>

Actions:
    view <tags> - Show active tasks, specified by tags.
    run <options> <tags> - Run suitable tasks, specified by tags (default action).
    help - Show this help.

Tags:
    [tag1],[tag2],[...],[tagN] - List of tags

Options:
    [--tagPrefix=value]
    [--interpreterPath=value]
    [--logsDir=value]
    [--logFile=value]
    [--bootstrapScript=value]
    [--timestamp=value]


RAW;
    }

    /**
     * Transform string datetime expressions to array sets
     *
     * @param array $timing
     * @return array
     */
    protected function transformDatePieces(array $timing)
    {
        $validTimes = [];
        $dimensions = [
            'min' => ['min' => 0, 'max' => 59], //Minutes
            'hour' => ['min' => 0, 'max' => 23], //Hours
            'day' => ['min' => 1, 'max' => 31], //Days
            'month' => ['min' => 1, 'max' => 12], //Months
            'dayofweek' => ['min' => 0, 'max' => 6], //Weekdays
        ];
        foreach ($timing AS $type => $value) {
            if (is_numeric($value) && ($value >= $dimensions[$type]['min'] && $value <= $dimensions[$type]['max'])) {
                $validTimes[$type][] = $value;
            } elseif ($value === '*') {
                $validTimes[$type] = range($dimensions[$type]['min'], $dimensions[$type]['max']);
            } elseif (preg_match('/\*\/\d+/', $value)) {
                $repeatDivider = explode('/', $value)[1];
                foreach (range($dimensions[$type]['min'], $dimensions[$type]['max']) as $range) {
                    if ($range === 0) {
                        $validTimes[$type][] = $range;
                    } elseif ($range % $repeatDivider === 0) {
                        $validTimes[$type][] = $range;
                    }
                }
            } elseif (preg_match('/[,\d+]/', $value)) {
                foreach (explode(',', $value) as $subval) {
                    if (is_numeric($subval) && ($subval >= $dimensions[$type]['min'] && $subval <= $dimensions[$type]['max'])) {
                        $validTimes[$type][] = $subval;
                    }
                }
            } else {
                throw new InvalidParamException('Invalid timing parameter: ' . $type . ' => ' . $value);
            }
        }
        return $validTimes;
    }

    /**
     * OS-independent background command execution .
     *
     * @param string $command
     * @param string $stdout path to file for writing stdout
     * @param string $stderr path to file for writing stderr
     */
    protected function runCommandBackground($command, $stdout, $stderr)
    {
        $concat = ($this->updateLogFile) ? ' >>' : ' >';
        $command =
            $this->interpreterPath . ' ' .
            $command .
            $concat . escapeshellarg($stdout) .
            ' 2>' . (($stdout === $stderr) ? '&1' : escapeshellarg($stderr));

        //nix based OS
        system($command . ' &');
    }

    /**
     * Running actions associated with cron runner and matched with timestamp.
     *
     * @param array $args List of run-tags to running actions (if empty, only "default" run-tag will be runned).
     *
     * @throws \ErrorException
     * @throws \Exception
     */
    public function actionRun(array $args = [])
    {

        $tags = $args ? $args : $this->defaultConfig['tags'];

        $time = strtotime($this->timestamp);
        $actions = $this->prepareActionsToRun($tags);

        if (empty($actions)) {
            Yii::info('No task on ' . date('r', $time), $this->logsCategory);
            return;
        }

        // Get run permission from super admin api if there are at least one task to run AND we are in a production environment
        // Defaults to true in dev or staging environment
        $superAdminRunPermission = YII_ENV_PROD ? $this->getSuperAdminRunPermission() : true;

        $runned = 0;
        foreach ($actions as $task) {
            if ($task['superAdminIntegration'] && !$superAdminRunPermission) {
                continue;
            }

            //Forming command to run
            $command = $this->bootstrapScript . ' ' . escapeshellcmd($task['command']);

            //Setting default stdout & stderr
            if (isset($task['stdout'])) {
                $stdout = $task['stdout'];
            } else {
                $stdout = $this->logFile;
            }

            $stdout = $this->formatFileName($stdout, $task);
            //if stdout does not exist then create the file
            if (!file_exists($stdout)) {
                //if stdout path does not exist then create the dir
                $stdout_path = pathinfo($stdout, PATHINFO_DIRNAME);
                if (!file_exists($stdout_path)) {
                    $old_umask = umask(0);
                    mkdir($stdout_path, 0777, true);
                    umask($old_umask);
                }
                touch($stdout);
            }

            if (!is_writable($stdout)) {
                $stdout = '/dev/null';
            }

            $stderr = isset($task['stderr']) ? $this->formatFileName($task['stderr'], $task) : $stdout;
            if (!is_writable($stderr)) {
                $stdout = '/dev/null';
            }

            $this->runCommandBackground($command, $stdout, $stderr);
            Yii::info('Running task [' . (++$runned) . ']: ' . $task['command'], $this->logsCategory);
        }
        if ($runned > 0) {
            Yii::info('Runned ' . $runned . ' task(s) at ' . date('r', $time), $this->logsCategory);
        } else {
            Yii::info('No task on ' . date('r', $time), $this->logsCategory);
        }
    }

    /**
     * Show actions associated with cron runner.
     *
     * @param array $args List of run-tags for filtering action list (if empty, show all).
     */
    public function actionView(array $args = [])
    {
        $tags = $args ? $args : $this->defaultConfig['tags'];

        foreach ($this->prepareActions() as $task) {
            if (!$tags || array_intersect($tags, $task['tags'])) {
                echo implode(' ', [
                        $task['timing']['min'],
                        $task['timing']['hour'],
                        $task['timing']['day'],
                        $task['timing']['month'],
                        $task['timing']['dayofweek'],
                        $task['command'],
                        '[' . $task['title'] . ']',
                        implode(', ', $task['tags']),
                    ]) . PHP_EOL;
            }
        }
    }

    /**
     * @param string $pattern
     * @param string $task
     * @return string mixed
     */
    protected function formatFileName($pattern, $task)
    {
        $pattern = str_replace(
            ['%R', '%T', '%C', '%P'],
            [Yii::getAlias('@runtime/logs'), $task['title'], str_replace('/', '.', $task['command']), getmypid()],
            $pattern
        );
        return preg_replace_callback('#%D\((.+)\)#U', create_function('$str', 'return date($str[1]);'), $pattern);
    }

    /**
     * Help command. Show command usage.
     */
    public function actionHelp()
    {
        echo $this->getHelp();
    }
    
    /**
     * Convert cronJob from simple string format like 
     * '0,30 10-20/5 * * * command/action param'
     * to array format like
     * [
     *          'timing' => [
     *              'min' => '0,30',
     *              'hour' => '10-20/5',
     *              'day' => '*',
     *              'month' => '*',
     *              'dayofweek' => '*',
     *          ],
     *          'command' => 'command/action param'
     *      ]
     * @param string $cronJobStr
     * @return array|boolean cronJob in array format or false on fail
     */
    protected function convertFromSimpleFormat($cronJobStr)
    {
        // parse string from format "min hour day month dayofweek command"
        if (preg_match("/^" . str_repeat('(?:([*\d\/\-,]+)\s+)', 5) . "(.+)$/", $cronJobStr, $matches)) {
            $cronJob = [
                'timing' => [
                    'min' => $matches[1],
                    'hour' => $matches[2],
                    'day' => $matches[3],
                    'month' => $matches[4],
                    'dayofweek' => $matches[5],
                ],
                'command' => $matches[6]
            ];
            return $cronJob;
        }
        return false; 
    }

    /**
     * Getting task list.
     *
     * @return array List of command actions associated with cron runner.
     *
     * @throws \ErrorException
     */
    protected function prepareActions()
    {
        $actions = [];
        try {
            $cronTab = ArrayHelper::getValue(Yii::$app->params, 'cronTab');
        } catch (\ErrorException $e) {
            throw new \ErrorException('Empty param cronTab in params array. ', 8);
        }
        if (!empty($cronTab)) {
            foreach ($cronTab as $title => $cronJob) {
                if (is_string($cronJob)) {
                    $cronJob = $this->convertFromSimpleFormat($cronJob);
                    if (!is_array($cronJob)) {
                        // if can't convert from simple format then skip this cronJob
                        continue;
                    }
                }
                $cronJob['enabled'] = ArrayHelper::getValue($cronJob, 'enabled', $this->defaultConfig['enabled']);
                $cronJob['superAdminIntegration'] = ArrayHelper::getValue($cronJob, 'superAdminIntegration', $this->defaultConfig['superAdminIntegration']);
                if (!array_key_exists('command', $cronJob) || !$cronJob['enabled']) {
                    continue;
                }
                $cronJob['title'] = is_numeric($title) ? "" : $title;
                $cronJob['tags'] = array_unique(array_merge(ArrayHelper::getValue($cronJob, 'tags', []), $this->defaultConfig['tags']));
                $cronJob['timing'] = ArrayHelper::getValue($cronJob, 'timing', $this->defaultConfig['timing']);
                foreach (array_keys($this->defaultConfig['timing']) as $key) {
                    $cronJob['timing'][$key] = ArrayHelper::getValue($cronJob, 'timing.' . $key, $this->defaultConfig['timing'][$key]);
                }
                $cronJob['timing_transformed'] = $this->transformDatePieces($cronJob['timing']);
                $actions[] = $cronJob;
            }
        }

        return $actions;
    }

    protected function prepareActionsToRun(array $currentTags)
    {
        $actions = [];
        $allActions = $this->prepareActions();

        //Getting timestamp will be used as current
        $time = strtotime($this->timestamp);
        if ($time === false) {
            throw new \Exception('Bad timestamp format');
        }
        $now = array_combine(['min', 'hour', 'day', 'month', 'dayofweek'], explode(' ', date('i G j n w', $time)));
        foreach ($allActions as $task) {
            if (empty(array_intersect($currentTags, $task['tags']))) {
                continue;
            }
            foreach ($now AS $key => $value) {
                //Checking current datetime on timestamp piece array.
                if (!in_array($value, $task['timing_transformed'][$key])) {
                    continue 2;
                }
            }

            $actions[] = $task;
        }

        return $actions;
    }

    /**
     * Get permission from super admin api to run cron commands
     *
     * @return bool
     */
    protected function getSuperAdminRunPermission()
    {
        if (!isset(Yii::$app->modules['admin']['params']['superadmin']['apiUrl'])) {
            Yii::warning("Superadmin integration turned on but API URL is not set", 'yii2-cron');
            return false;
        }

        if (!isset(Yii::$app->modules['admin']['params']['superadmin']['appToken'])) {
            Yii::warning("Superadmin integration turned on but APP TOKEN is not set", 'yii2-cron');
            return false;
        }

        $apiUrl = Yii::$app->modules['admin']['params']['superadmin']['apiUrl'];
        $appToken = Yii::$app->modules['admin']['params']['superadmin']['appToken'];

        $apiEndpoint = explode('/', $apiUrl);
        array_pop($apiEndpoint);
        $apiEndpoint[] = 'cron';
        $apiEndpoint[] = $appToken;
        $apiEndpoint = implode('/', $apiEndpoint);

        try {
            $response = file_get_contents($apiEndpoint);
        } catch (\Exception $e) {
            Yii::warning("Superadmin integration turned on but cannot get API response:\n$e", 'yii2-cron');
            return false;
        }

        try {
            $response = Json::decode($response);
        } catch (\Exception $e) {
            Yii::warning("Superadmin integration turned on but cannot decode API response JSON:\n$e", 'yii2-cron');
            return false;
        }

        if (!array_key_exists('status', $response)) {
            Yii::warning("Superadmin integration turned on but cannot find `status` key in API response JSON", 'yii2-cron');
            return false;
        }

        if (ArrayHelper::getValue($response, 'status', null) === true) {
            return true;
        }

        return false;
    }
}
