<?php

namespace Core\Console;

class Process
{
    public function run(array $command, string $directory): int
    {
        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $directory);
        if (!is_resource($process)) {
            throw new ConsoleException('The development server could not be started.');
        }
        try {
            return proc_close($process);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
}
