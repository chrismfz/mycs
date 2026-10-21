<?php

namespace App\Exceptions;

use App\Logging\WriteFailures;
use Illuminate\Foundation\Exceptions\Handler;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ErrorHandler extends Handler
{
    /**
     * report() never throws (#290): a log that cannot be written, on any channel, would leave the catch that called it
     * and skip what comes after (the cron's later steps, the other customers of a step). Kept in WriteFailures instead,
     * which the cron mails to staff.
     */
    protected function reportThrowable(Throwable $e): void
    {
        try {
            parent::reportThrowable($e);
        } catch (Throwable $failure) {
            // No logger at all: Laravel throws the reported exception itself again.
            WriteFailures::record($failure === $e ? new RuntimeException("No logger to report {$e->getMessage()}") : $failure);
        }
    }

    // We are overriding this method to change the view namespace separator from '::' to '.' (so themes can override error views)
    /**
     * Get the view used to render HTTP exceptions.
     *
     * @return string|null
     */
    protected function getHttpExceptionView(HttpExceptionInterface $e)
    {
        $view = 'errors.' . $e->getStatusCode();

        if (view()->exists($view)) {
            return $view;
        }

        $view = substr($view, 0, -2) . 'xx';

        if (view()->exists($view)) {
            return $view;
        }

        return null;
    }
}
