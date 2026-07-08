<?php

namespace ComposerProvider;

class Scripts
{
    public static function postInstall(): void {} // post-install-cmd script
    public static function first(): void {} // multiple script
    public static function withLeadingSlash(): void {} // multiple script, leading backslash
    public static function notAPhpScript(): void {} // error: Unused ComposerProvider\Scripts::notAPhpScript
    public static function unused(): void {} // error: Unused ComposerProvider\Scripts::unused
}

class ScriptsParent
{
    public static function inheritedHook(): void {} // inherited script referenced via Child
}

class Child extends ScriptsParent
{
}
