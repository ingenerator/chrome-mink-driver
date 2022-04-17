<?php

namespace DMore\ChromeDriver;

class StreamReadException extends \Exception
{
    /**
     * @var bool
     */
    private $eof;
    /**
     * @var bool
     */
    private $timed_out;
    /**
     * @var bool
     */
    private $blocked;

    public function __construct($message, $state, $exception)
    {
        $this->message = $message;
        $this->eof = $state['eof'];
        $this->timed_out = $state['timed_out'];
        $this->blocked = $state['blocked'];
    }

    /**
     * @return boolean
     */
    public function isEof()
    {
        return $this->eof;
    }

    /**
     * @return boolean
     */
    public function isTimedOut()
    {
        return $this->timed_out;
    }

    /**
     * @return boolean
     */
    public function isBlocked()
    {
        return $this->blocked;
    }
}
