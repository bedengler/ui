<?php

// vim:ts=4:sw=4:et:fdm=marker:fdl=0

namespace atk4\ui;

/**
 * Console is a black square component resembling terminal window. It can be programmed
 * to run a job and output results to the user.
 */
class Console extends View implements \Psr\Log\LoggerInterface
{
    public $ui = 'inverted black segment';
    public $element = 'pre';

    /**
     * Specify which event will trigger this console. Set to 'false'
     * to disable automatic triggering if you need to trigger it
     * manually.
     *
     * @var bool
     */
    public $event = true;

    /**
     * Will be set to $true while executing callback. Some methods
     * will use this to automatically schedule their own callback
     * and allowing you a cleaner syntax, such as.
     *
     * $console->setModel($user, 'generateReport');
     *
     * @var bool
     */
    protected $sseInProgress = false;

    /**
     * Stores object jsSSE which is used for communication.
     *
     * @var jsSSE
     */
    public $sse;

    /**
     * Bypass is used internally to capture and wrap direct output, but prevent jsSSE from
     * triggering output recursively.
     *
     * @var bool
     */
    public $_output_bypass = false;

    /**
     * Set a callback method which will be executed with the output sent back to the terminal.
     *
     * Argument passed to your callback will be $this Console. You may perform calls
     * to methods such as
     *
     *   $console->output()
     *   $console->outputHTML()
     *
     * If you are using setModel, and if your model implements atk4\core\DebugTrait,
     * then you you will see debug information generated by $this->debug() or $this->log().
     *
     * This intercepts default application logging for the duration of the process.
     *
     * If you are using runCommand, then server command will be executed with it's output
     * (STDOUT and STDERR) redirected to the console.
     *
     * While inside a callback you may execute runCommand or setModel multiple times.
     *
     * @param callable    $callback callback which will be executed while displaying output inside console
     * @param bool|string $event    "true" would mean to execute on page load, string would indicate
     *                              js event. See first argument for View::js()
     *
     * @return $this
     */
    public function set($callback = null, $event = null)
    {
        if (!$callback) {
            throw new Exception('Please specify the $callback argument');
        }

        if (isset($event)) {
            $this->event = $event;
        }

        $this->sse = jsSSE::addTo($this);
        $this->sse->set(function () use ($callback) {
            $this->sseInProgress = true;

            if (isset($this->app)) {
                $old_logger = $this->app->logger;
                $this->app->logger = $this;
            }

            try {
                ob_start(function ($content) {
                    if ($this->_output_bypass) {
                        return $content;
                    }

                    $output = '';
                    $this->sse->echoFunction = function ($str) use (&$output) {
                        $output .= $str;
                    };
                    $this->output($content);
                    $this->sse->echoFunction = false;

                    return $output;
                }, 2);

                call_user_func($callback, $this);
            } catch (\atk4\core\Exception $e) {
                $lines = explode("\n", $e->getHTMLText());

                foreach ($lines as $line) {
                    $this->outputHTML($line);
                }
            } catch (\Error $e) {
                $this->output('Error: ' . $e->getMessage());
            } catch (\Exception $e) {
                $this->output('Exception: ' . $e->getMessage());
            }

            if (isset($this->app)) {
                $this->app->logger = $old_logger;
            }

            $this->sseInProgress = false;
        });

        if ($this->event) {
            $this->js($this->event, $this->jsExecute());
        }

        return $this;
    }

    /**
     * Return JavaScript expression to execute console.
     *
     * @return jsExpressionable
     */
    public function jsExecute()
    {
        return $this->sse;
    }

    /**
     * Output a single line to the console.
     *
     * @param string $message
     * @param array  $context
     *
     * @return $this
     */
    public function output($message, array $context = [])
    {
        $this->outputHTML(htmlspecialchars($message), $context);

        return $this;
    }

    /**
     * Output un-escaped HTML line. Use this to send HTML.
     *
     * @todo Use $message as template and fill values from $context in there.
     *
     * @param string $message
     * @param array  $context
     *
     * @return $this
     */
    public function outputHTML($message, $context = [])
    {
        $message = preg_replace_callback('/{([a-z0-9_-]+)}/i', function ($match) use ($context) {
            if (isset($context[$match[1]]) && is_string($context[$match[1]])) {
                return $context[$match[1]];
            }

            // don't change the original message
            return '{' . $match[1] . '}';
        }, $message);

        $this->_output_bypass = true;
        $this->sse->send($this->js()->append($message . '<br/>'));
        $this->_output_bypass = false;

        return $this;
    }

    public function renderView()
    {
        $this->addStyle('overflow-x', 'auto');

        return parent::renderView();
    }

    /**
     * Executes a JavaScript action.
     *
     * @param jsExpressionable $js
     *
     * @return $this
     */
    public function send($js)
    {
        $this->_output_bypass = true;
        $this->sse->send($js);
        $this->_output_bypass = false;

        return $this;
    }

    public $last_exit_code = null;

    /**
     * Executes command passing along escaped arguments.
     *
     * Will also stream stdout / stderr as the comand executes.
     * once command terminates method will return the exit code.
     *
     * This method can be executed from inside callback or
     * without it.
     *
     * Example: runCommand('ping', ['-c', '5', '8.8.8.8']);
     *
     * All arguments are escaped.
     */
    public function exec($exec, $args = [])
    {
        if (!$this->sseInProgress) {
            $this->set(function () use ($exec, $args) {
                $a = $args ? (' with ' . count($args) . ' arguments') : '';
                $this->output('--[ Executing ' . $exec . $a . ' ]--------------');

                $this->exec($exec, $args);

                $this->output('--[ Exit code: ' . $this->last_exit_code . ' ]------------');
            });

            return;
        }

        list($proc, $pipes) = $this->execRaw($exec, $args);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        // $pipes contain streams that are still open and not EOF
        while ($pipes) {
            $read = $pipes;
            $j1 = $j2 = null;
            if (stream_select($read, $j1, $j2, 2) === false) {
                throw new Exception(['stream_select() returned false.']);
            }

            $stat = proc_get_status($proc);
            if (!$stat['running']) {
                proc_close($proc);
                break;
            }

            foreach ($read as $f) {
                $data = fgets($f);
                $data = rtrim($data);
                if (!$data) {
                    continue;
                }

                if ($f === $pipes[2]) {
                    // STDERR
                    $this->warning($data);
                } else {
                    // STDOUT
                    $this->output($data);
                }
            }
        }

        $this->last_exit_code = $stat['exitcode'];

        return $this->last_exit_code ? false : $this;
    }

    protected function execRaw($exec, $args = [])
    {
        // Escape arguments
        foreach ($args as $key => $val) {
            if (!is_scalar($val)) {
                throw new Exception(['Arguments must be scalar', 'arg'=>$val]);
            }
            $args[$key] = escapeshellarg($val);
        }

        $exec = escapeshellcmd($exec);
        $spec = [1=>['pipe', 'w'], 2=>['pipe', 'w']]; // we want stdout and stderr
        $pipes = null;
        $proc = proc_open($exec . ' ' . implode(' ', $args), $spec, $pipes);
        if (!is_resource($proc)) {
            throw new Exception(['Command failed to execute', 'exec'=>$exec, 'args'=>$args]);
        }

        return [$proc, $pipes];
    }

    /**
     * This method is obsolete. Use Console::runMethod() instead.
     */
    public function setModel(\atk4\data\Model $model, $method = null, $args = [])
    {
        $this->runMethod($model, $method, $args);

        return $model;
    }

    /**
     * Execute method of a certain object. If object uses atk4/core/DebugTrait,
     * then debugging will also be used.
     *
     * During the invocation, Console will substitute $app->logger with itself,
     * capturing all debug/info/log messages generated by your code and displaying
     * it inside console.
     *
     * // Runs $user_model->generateReport('pdf')
     * Console::addTo($app)->runMethod($user_model, 'generateReports', ['pdf']);
     *
     * // Runs PainFactory::lastStaticMethod()
     * Console::addTo($app)->runMethod('PainFactory', 'lastStaticMethod');
     *
     * To produce output:
     *  - use $this->debug() or $this->info() (see documentation on DebugTrait)
     *
     * NOTE: debug() method will only output if you set debug=true. That is done
     * for the $user_model automatically, but for any nested objects you would have
     * to pass on the property.
     *
     * @param object          $object
     * @param string|callable $method
     * @param array           $args
     *
     * @return $this
     */
    public function runMethod($object, $method, $args = [])
    {
        if (!$this->sseInProgress) {
            $this->set(function () use ($object, $method, $args) {
                $this->runMethod($object, $method, $args);
            });

            return $this;
        }

        // temporarily override app logging
        if (isset($object->app)) {
            $old_logger = $object->app->logger;
            $object->app->logger = $this;
        }

        if (is_object($object)) {
            $this->output('--[ Executing ' . get_class($object) . '->' . $method . ' ]--------------');
            $object->debug = true;
            $result = call_user_func_array([$object, $method], $args);
        } elseif (is_string($object)) {
            $static = $object . '::' . $method;
            $this->output('--[ Executing ' . $static . ' ]--------------');
            $result = call_user_func_array($object . '::' . $method, $args);
        } else {
            throw new Exception(['Incorrect value for an object', 'object'=>$object]);
        }
        $this->output('--[ Result: ' . json_encode($result) . ' ]------------');

        if (isset($object->app)) {
            $object->app->logger = $old_logger;
        }

        return $this;
    }

    // Methods below implements \Psr\Log\LoggerInterface

    /**
     * System is unusable.
     *
     * @param string $message
     * @param array  $context
     */
    public function emergency($message, array $context = [])
    {
        $this->outputHTML("<font color='pink'>" . htmlspecialchars($message) . '</font>', $context);
    }

    /**
     * Action must be taken immediately.
     *
     * @param string $message
     * @param array  $context
     */
    public function alert($message, array $context = [])
    {
        $this->outputHTML("<font color='pink'>" . htmlspecialchars($message) . '</font>', $context);
    }

    /**
     * Critical conditions.
     *
     * @param string $message
     * @param array  $context
     */
    public function critical($message, array $context = [])
    {
        $this->outputHTML("<font color='pink'>" . htmlspecialchars($message) . '</font>', $context);
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param string $message
     * @param array  $context
     */
    public function error($message, array $context = [])
    {
        $this->outputHTML("<font color='pink'>" . htmlspecialchars($message) . '</font>', $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * @param string $message
     * @param array  $context
     */
    public function warning($message, array $context = [])
    {
        $this->outputHTML("<font color='pink'>" . htmlspecialchars($message) . '</font>', $context);
    }

    /**
     * Normal but significant events.
     *
     * @param string $message
     * @param array  $context
     */
    public function notice($message, array $context = [])
    {
        $this->outputHTML("<font color='yellow'>" . htmlspecialchars($message) . '</font>', $context);
    }

    /**
     * Interesting events.
     *
     * @param string $message
     * @param array  $context
     */
    public function info($message, array $context = [])
    {
        $this->outputHTML("<font color='gray'>" . htmlspecialchars($message) . '</font>', $context);
    }

    /**
     * Detailed debug information.
     *
     * @param string $message
     * @param array  $context
     */
    public function debug($message, array $context = [])
    {
        $this->outputHTML("<font color='cyan'>" . htmlspecialchars($message) . '</font>', $context);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     */
    public function log($level, $message, array $context = [])
    {
        $this->$level($message, $context);
    }
}
