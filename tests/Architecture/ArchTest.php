<?php

// Forbids debugging helpers (dd, dump, var_dump, ray, die, exit, ...) and deprecated PHP functions in app code.
arch()->preset()->php();

// Forbids insecure functions (eval, md5, sha1, rand, unserialize, shell_exec, ...) in app code.
arch()->preset()->security();
