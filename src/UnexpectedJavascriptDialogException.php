<?php


namespace DMore\ChromeDriver;


use Behat\Mink\Exception\DriverException;

/**
 * Thrown if the browser presents a native dialog (alert, prompt, etc) and it cannot be handled
 */
class UnexpectedJavascriptDialogException extends DriverException
{

    public function __construct(array $dialog_params, \Throwable $error)
    {
        return parent::__construct(
            sprintf(
                'Unexpected %s browser dialog: `%s` [%s]',
                $dialog_params['type'],
                $dialog_params['message'],
                $error->getMessage()
            ),
            0,
            $error
        );
    }

}
