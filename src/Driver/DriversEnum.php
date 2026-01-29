<?php

namespace PhpSoftBox\Database\Driver;

enum DriversEnum: string
{
    case MARIADB  = 'mariadb';
    case MYSQL    = 'mysql';
    case POSTGRES = 'pgsql';
    case SQLITE   = 'sqlite';
}
