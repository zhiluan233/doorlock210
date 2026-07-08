<?php
namespace anim210System;

use anim210System;

anim210System\Utils::checkCsrf();

unset($_SESSION['user']);
unset($_SESSION['mail']);
unset($_SESSION['member_open_id']);
unset($_SESSION['member_name']);
unset($_SESSION['member_token']);
unset($_SESSION['token']);
?>
<script>location='/?page=login';</script>
