<?php

namespace App\Helpers;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class Sanitize
{
    /**
     * https://github.com/tgalopin/html-sanitizer/blob/master/docs/1-getting-started.md#extensions
     */
    public static function sanitizeHtml($html, $extensions = ['basic'])
    {
        $config = new HtmlSanitizerConfig();

        // Basic safe elements (rough equivalent of old 'basic' extension)
        if (in_array('basic', $extensions, true)) {
            $config->allowSafeElements();
        }

        // Always ensure these specific elements + attributes
        $elements = [
            'span' => ['class', 'id'],
            'div'  => ['class', 'id'],
            'img'  => ['src', 'alt', 'title', 'class', 'id'],
            'a'    => ['href', 'title', 'class', 'id', 'target', 'rel'],
        ];

        foreach ($elements as $tag => $attributes) {
            $config->allowElement($tag);
            foreach ($attributes as $attr) {
                $config->allowAttribute($attr, $tag);
            }
        }

        // Allow common link schemes
        $config->allowLinkSchemes(['http', 'https', 'mailto']);

        // (Optional) allow relative URLs in links/images
        $config->allowRelativeLinks();

        // (Optional) allow data URIs for images if desired (uncomment if needed)
        // $config->allowMediaSchemes(['data']);

        $sanitizer = new HtmlSanitizer($config);

        return $sanitizer->sanitize($html ?? '');
    }

    // Function to unsanitize only certain safe elements in HTML
    public static function unsanitizeSafeHtml($html, $safeElements = [])
    {
        // Pattern to match sanitized HTML elements (opening and closing tags)
        $pattern = '/&lt;([a-zA-Z0-9]+)(.*?)&gt;(.*?)&lt;\/\1&gt;/is';

        $replacement = function($matches) use ($safeElements) {
            // Get the tag name
            $tagName = strtolower($matches[1]);

            // If the tag is not in the allowed safe elements, return it unchanged
            if (!array_key_exists($tagName, $safeElements)) {
                return $matches[0];
            }

            // Extract the attributes part of the tag
            $attributes = $matches[2];
            preg_match_all('/\s*([\w-]+)="([^"]*)"/i', $attributes, $attrMatches);

            // Get allowed attributes for the current tag
            $allowedAttributes = $safeElements[$tagName];

            // Rebuild the tag with only the allowed attributes
            $validAttributes = [];
            foreach ($attrMatches[1] as $index => $attrName) {
                // If attribute matches one of the allowed attributes, keep it
                if (in_array($attrName, $allowedAttributes)) {
                    $validAttributes[] = $attrMatches[0][$index];
                }
            }

            // Build the final string with allowed attributes
            $validAttributesString = implode(' ', $validAttributes);

            // Return the unsanitized tag with valid attributes
            return "<$tagName$validAttributesString>$matches[3]</$tagName>";
        };

        // Apply the replacement to the HTML string
        return preg_replace_callback($pattern, $replacement, $html);
    }

}