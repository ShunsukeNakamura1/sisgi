<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitba487ab24d1ca96168040c181b2420f2
{
    public static $prefixLengthsPsr4 = array (
        'L' => 
        array (
            'LINE\\' => 5,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'LINE\\' => 
        array (
            0 => __DIR__ . '/..' . '/linecorp/line-bot-sdk/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitba487ab24d1ca96168040c181b2420f2::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitba487ab24d1ca96168040c181b2420f2::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
