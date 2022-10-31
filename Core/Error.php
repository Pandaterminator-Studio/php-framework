<?php

namespace Core;

use ErrorException;

class Error
{
    /**
     * @throws ErrorException
     */
    public static function errorHandler($level, $message, $file, $line): void
    {
        if(error_reporting() !== 0){
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }

    public static function exceptionHandler($exception): void
    {
        $code = $exception->getCode();
        if($code != 404){
            $code = 500;
        }
        http_response_code($code);

        if(\App\Config::SHOW_ERRORS){ ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>ERROR</title>
                <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
                <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
                <script src="https://code.jquery.com/jquery-3.6.1.min.js" integrity="sha256-o88AwQnZB+VDvE9tvIXrMQaPlFFSUTR+nldQm1LuPXQ=" crossorigin="anonymous"></script>
            </head>

            <body>



            <div class="row">
                <div class="col s12 m12">
                    <div class="card-panel red">

                        <h1>Fatal error</h1>
                        <p><b>Uncaught exception</b>: ' <?php echo get_class($exception) ;?>'</p>
                        <p><b>Message</b>: <?php echo $exception->getMessage(); ?></p>
                        <p><b>Stack trace</b>: <?php echo $exception->getTraceAsString(); ?></p>
                        <p><b>Thrown in</b> '<?php echo $exception->getFile(); ?>' <b>on line</b> <?php echo $exception->getLine(); ?> </p>

                    </div>
                </div>
            </div>


            <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
            <script>M.AutoInit();</script>
            </body>
            </html>
        <?php } else {
            $log = dirname(__DIR__).'/logs/'.date('Y-m-d').'.txt';
            ini_set('error_log', $log);
            $message = "Uncaught exception: '" . get_class($exception)."'";
            $message .= " with message: '" . $exception->getMessage()."'";
            $message .= "\nStack trace: " . $exception->getTraceAsString();
            $message .= "\nThrown in '" . $exception->getFile(). "' on line " . $exception->getLine();

            error_log($message);
            if($code = 404) header("Location: /AppError/index");
            if($code = 500) header("Location: /AppError/error");
        }
    }
}