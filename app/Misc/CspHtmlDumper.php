<?php

namespace App\Misc;

use Illuminate\Foundation\Http\HtmlDumper;

/**
 * Laravel's dump() output for the browser, with the CSP nonce on its inline
 * scripts so the Content-Security-Policy header doesn't block them.
 */
class CspHtmlDumper extends HtmlDumper
{
    protected function getDumpHeader(): string
    {
        $this->dumpSuffix = $this->addNonce($this->dumpSuffix);

        return $this->addNonce(parent::getDumpHeader());
    }

    protected function addNonce($html)
    {
        return str_replace('<script>', '<script'.\Helper::cspNonceAttr().'>', $html);
    }
}
