<?php
namespace LinguaFlowAI\Security;

/** Sanitize every write, including approved/imported memories and edited reviews. */
final class ProductContent
{
    public static function sanitize(string $field, string $value): string
    {
        if (!in_array($field, ['description', 'description_short'], true)) {
            return strip_tags($value);
        }
        static $purifier;
        if ($purifier === null) {
            if (!class_exists('HTMLPurifier') || !class_exists('HTMLPurifier_Config')) {
                throw new \RuntimeException('HTMLPurifier is required to save translated descriptions safely.');
            }
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.DefinitionImpl', null);
            $config->set('HTML.Allowed', 'p,h1,h2,h3,h4,h5,h6,ul,ol,li,div,span,strong,b,em,i,u,br,table,thead,tbody,tfoot,tr,td[colspan|rowspan],th[colspan|rowspan],blockquote,hr,a[href|title|target|rel],img[src|alt|title|width|height]');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $purifier = new \HTMLPurifier($config);
        }
        return $purifier->purify($value);
    }
}
