<?php

namespace WebPExpress;

use \WebPExpress\ConvertHelperIndependent;
use \WebPExpress\Config;
use \WebPExpress\ConvertersHelper;
use \WebPExpress\SanityCheck;
use \WebPExpress\SanityException;
use \WebPExpress\Validate;
use \WebPExpress\ValidateException;

class Convert
{

    public static function getDestination($source, &$config = null)
    {
        if (is_null($config)) {
            $config = Config::loadConfigAndFix();
        }
        return ConvertHelperIndependent::getDestination(
            $source,
            $config['destination-folder'],
            $config['destination-extension'],
            Paths::getWebPExpressContentDirAbs(),
            Paths::getUploadDirAbs()
        );
    }

    public static function convertFile($source, $config = null, $convertOptions = null, $converter = null)
    {
        try {
            // Check source
            // ---------------
            $checking = 'source path';
            $source = SanityCheck::absPathExistsAndIsFile();
            //$filename = SanityCheck::absPathExistsAndIsFileInDocRoot($source);
            // PS: No need to check mime type as the WebPConvert library does that (it only accepts image/jpeg and image/png)


            // Check config
            // --------------
            $checking = 'configuration file';
            if (is_null($config)) {
                $config = Config::loadConfigAndFix();  // ps: if this fails to load, default config is returned.
            }
            if (!is_array($config)) {
                throw new SanityException('file is corrupt');
            }

            // Check convert options
            // -------------------------------
            $checking = 'configuration file (options)';
            if (is_null($convertOptions)) {
                $wodOptions = Config::generateWodOptionsFromConfigObj($config);
                if (!isset($wodOptions['webp-convert']['convert'])) {
                    throw new SanityException('conversion options are missing');
                }
                $convertOptions = $wodOptions['webp-convert']['convert'];
            }
            if (!is_array($convertOptions)) {
                throw new SanityException('conversion options are missing');
            }


            // Check destination
            // -------------------------------
            $checking = 'destination';
            $destination = self::getDestination($source, $config);

            $destination = SanityCheck::absPathIsInDocRoot($destination);

            // Check log dir
            // -------------------------------
            $checking = 'conversion log dir';
            $logDir = SanityCheck::absPathIsInDocRoot(Paths::getWebPExpressContentDirAbs() . '/log');


        } catch (SanityException $e) {
            return [
                'success' => false,
                'msg' => 'Sanitation check failed for ' . $checking . ': '. $e->getMessage(),
                'log' => '',
            ];
        }

        // Done with sanitizing, lets get to work!
        // ---------------------------------------

        $result = ConvertHelperIndependent::convert($source, $destination, $convertOptions, $logDir, $converter);

        if ($result['success'] === true) {
            $result['filesize-original'] = @filesize($source);
            $result['filesize-webp'] = @filesize($destination);
        }
        return $result;
    }

    /**
     *  Determine the location of a source from the location of a destination.
     *
     *  If for example Operation mode is set to "mingled" and extension is set to "Append .webp",
     *  the result of looking passing "/path/to/logo.jpg.webp" will be "/path/to/logo.jpg".
     *
     *  Additionally, it is tested if the source exists. If not, false is returned.
     *  The destination does not have to exist.
     *
     *  @return  string|null  The source path corresponding to a destination path
     *                        - or false on failure (if the source does not exist or $destination is not sane)
     *
     */
    public static function findSource($destination, &$config = null)
    {
        try {
            // Check that destination path is sane and inside document root
            $destination = SanityCheck::absPathIsInDocRoot($destination);
        } catch (SanityException $e) {
            return false;
        }

        // Load config if not already loaded
        if (is_null($config)) {
            $config = Config::loadConfigAndFix();
        }

        return ConvertHelperIndependent::findSource(
            $destination,
            $config['destination-folder'],
            $config['destination-extension'],
            Paths::getWebPExpressContentDirAbs()
        );
    }

    public static function processAjaxConvertFile()
    {

        if (!check_ajax_referer('webpexpress-ajax-convert-nonce', 'nonce', false)) {
            wp_send_json_error('Invalid security nonce (it has probably expired - try refreshing)');
            wp_die();
        }

        // Check input
        // --------------
        try {
            // Check "filename"
            $checking = '"filename" argument';
            Validate::postHasKey('filename');

            $filename = sanitize_text_field(stripslashes($_POST['filename']));

            // holy moly! Wordpress automatically adds slashes to the global POST vars - https://stackoverflow.com/questions/2496455/why-are-post-variables-getting-escaped-in-php
            $filename = wp_unslash($_POST['filename']);

            //$filename = SanityCheck::absPathExistsAndIsFileInDocRoot($filename);
            // PS: No need to check mime version as webp-convert does that.


            // Check converter id
            // ---------------------
            $checking = '"converter" argument';
            if (isset($_POST['converter'])) {
                $converterId = sanitize_text_field($_POST['converter']);
                Validate::isConverterId($converterId);
            }


            // Check "config-overrides"
            // ---------------------------
            $checking = '"config-overrides" argument';
            if (isset($_POST['config-overrides'])) {
                $configOverridesJSON = SanityCheck::noControlChars($_POST['config-overrides']);
                $configOverridesJSON = preg_replace('/\\\\"/', '"', $configOverridesJSON); // We got crazy encoding, perhaps by jQuery. This cleans it up

                $configOverridesJSON = SanityCheck::isJSONObject($configOverridesJSON);
                $configOverrides = json_decode($configOverridesJSON, true);

                // PS: We do not need to validate the overrides.
                // webp-convert checks all options. Nothing can be passed to webp-convert which causes harm.
            }

        } catch (SanityException $e) {
            wp_send_json_error('Sanitation check failed for ' . $checking . ': '. $e->getMessage());
            wp_die();
        } catch (ValidateException $e) {
            wp_send_json_error('Validation failed for ' . $checking . ': '. $e->getMessage());
            wp_die();
        }


        // Input has been processed, now lets get to work!
        // -----------------------------------------------
        if (isset($configOverrides)) {
            $config = Config::loadConfigAndFix();


            // convert using specific converter
            if (!is_null($converterId)) {

                // Merge in the config-overrides (config-overrides only have effect when using a specific converter)
                $config = array_merge($config, $configOverrides);

                $converter = ConvertersHelper::getConverterById($config, $converterId);
                if ($converter === false) {
                    wp_send_json_error('Converter could not be loaded');
                    wp_die();
                }

                // the converter options stored in config.json is not precisely the same as the ones
                // we send to webp-convert.
                // We need to "regenerate" webp-convert options in order to use the ones specified in the config-overrides
                // And we need to merge the general options (such as quality etc) into the option for the specific converter

                $generalWebpConvertOptions = Config::generateWodOptionsFromConfigObj($config)['webp-convert']['convert'];
                $converterSpecificWebpConvertOptions = $converter['options'];

                $webpConvertOptions = array_merge($generalWebpConvertOptions, $converterSpecificWebpConvertOptions);
                unset($webpConvertOptions['converters']);

                // what is this? - I forgot why!
                //$config = array_merge($config, $converter['options']);
                $result = self::convertFile($filename, $config, $webpConvertOptions, $converterId);

            } else {
                $result = self::convertFile($filename, $config);
            }
        } else {
            $result = self::convertFile($filename);
        }

        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        wp_die();
    }

}
