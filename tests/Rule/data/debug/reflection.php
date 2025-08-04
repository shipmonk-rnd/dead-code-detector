<?php

namespace DebugReflection;

$reflection = new \ReflectionClass($unknown);
$reflection->getMethods(); // "unknown over unknown", but via reflection, we decided not to report this
