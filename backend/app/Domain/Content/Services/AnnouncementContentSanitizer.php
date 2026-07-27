<?php

namespace App\Domain\Content\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

class AnnouncementContentSanitizer
{
    private HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', 'p,br,h2,h3,h4,strong,b,em,i,ul,ol,li,a[href|title],table,thead,tbody,tr,th,td');
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
        ]);
        $config->set('Attr.EnableID', false);
        $config->set('AutoFormat.RemoveEmpty', false);
        $config->set('Cache.DefinitionImpl', null);

        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitizeForStorage(string $body): string
    {
        if ($this->isPlainText($body)) {
            return $body;
        }

        return $this->purifier->purify($body);
    }

    public function render(string $body): string
    {
        if ($this->isPlainText($body)) {
            return nl2br(
                htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                false,
            );
        }

        return $this->purifier->purify($body);
    }

    private function isPlainText(string $body): bool
    {
        return strip_tags($body) === $body;
    }
}
