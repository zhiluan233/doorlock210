<?php
namespace anim210System;

use anim210System;

anim210System\Utils::checkCsrf();

unset($_SESSION['user']);
unset($_SESSION['mail']);
?>
<script>location='/?page=login';</script>