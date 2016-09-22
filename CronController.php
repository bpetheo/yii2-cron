<?php

namespace mito\yii2cron;

use Yii;
use yii\base\InvalidParamException;
use yii\console\Controller;
use yii\helpers\ArrayHelper;


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
     *     %C - name of command
     *     %P - pid of runner-script (current)
     *     %D(string formatted as arg of date() function) - formatted date
     * @var string mask log file name
     */
    public $logFile = '%R/cron/%T.%D(Y-m-d).log';
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

    /* Overriding stdout, first calling the parent impl which will output to the screen, and then storing the string */
    public function stdout($string)
    {
//        parent::stdout($string);
//        $this->output = $this->output.$string."\n";
        Yii::trace($string, 'cron');
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

        $tags = array_unique(array_merge($args, $this->defaultConfig['tags']));

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
                    mkdir($stdout_path, '0777', true);
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
        $tags = array_unique(array_merge($args, $this->defaultConfig['tags']));

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
            array('%R', '%T', '%C', '%P'),
            array(Yii::getAlias('@runtime/logs'), $task['title'], str_replace('/', '.', $task['command']), getmypid()),
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
                $cronJob['enabled'] = ArrayHelper::getValue($cronJob, 'enabled', $this->defaultConfig['enabled']);
                $cronJob['superAdminIntegration'] = ArrayHelper::getValue($cronJob, 'superAdminIntegration', $this->defaultConfig['superAdminIntegration']);
                if (empty($title) || !isset($cronJob['command']) || !$cronJob['enabled']) {
                    continue;
                }
                $cronJob['title'] = $title;
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
        return true;
    }
}
