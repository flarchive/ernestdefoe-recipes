<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'ernestdefoe-recipes.uploadPhotos' => Group::MEMBER_ID,
]);
