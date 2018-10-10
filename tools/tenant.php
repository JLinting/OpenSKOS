<?php

/**
 * OpenSKOS
 *
 * LICENSE
 *
 * This source file is subject to the GPLv3 license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   OpenSKOS
 * @package    OpenSKOS
 * @copyright  Copyright (c) 2011 Pictura Database Publishing. (http://www.pictura-dp.nl)
 * @author     Mark Lindeman
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GPLv3
 */

use OpenSkos2\Namespaces\Rdf;
use OpenSkos2\Institution;
use OpenSkos2\Set;
use OpenSkos2\Rdf\Uri;

require 'autoload.inc.php';
require 'Zend/Console/Getopt.php';

require_once 'utils_functions.php';

$opts = array(
    'help|?' => 'Print this usage message',
    'env|e=s' => 'The environment to use (defaults to "production")',
    'code=s' => 'Institution code (required)',
    'name=s' => 'Institution name (required when creating a tenant)',
    'email=s' => 'Admin email (required when creating a tenant)',
    'password=s' => 'Password for the Admin account'
);
$OPTS = new Zend_Console_Getopt($opts);

if ($OPTS->help) {
    echo str_replace('[ options ]', '[ options ] action', $OPTS->getUsageMessage());
    exit(0);
}

$args = $OPTS->getRemainingArgs();

if (!$args || count($args) != 1) {
    echo str_replace('[ options ]', '[ options ] action', $OPTS->getUsageMessage());
    fwrite(STDERR, "Expected an actions (create|delete)\n");
    exit(1);
}
$action = $args[0];

$query = $OPTS->query;

if (null === $OPTS->code) {
    fwrite(STDERR, "missing required `code` argument\n");
    exit(1);
}

include 'bootstrap.inc.php';

/* @var $diContainer DI\Container */
$diContainer = Zend_Controller_Front::getInstance()->getDispatcher()->getContainer();
$resourceManager = $diContainer->make('\OpenSkos2\Rdf\ResourceManager');
$tenantManager = $diContainer->make('\OpenSkos2\InstitutionManager');

switch ($action) {
    case 'create':
        if (null === $OPTS->name) {
            fwrite(STDERR, "missing required `name` argument\n");
            exit(1);
        }
        if (null === $OPTS->email) {
            fwrite(STDERR, "missing required `email` argument\n");
            exit(1);
        }
        if (null === $OPTS->password) {
            $password = OpenSKOS_Db_Table_Users::pwgen(8);
        } else {
            $password = $OPTS->password;
        }
        try {

            $tenantCode = $OPTS->code;
            $tenantName = $OPTS->name;
            $tenantEmail = $OPTS->email;

            if (empty($tenantName)) {
                $tenantName = $tenantCode;
            }
            $tenant = new Institution();

            $tenant->arrayToData(
                array(
                    'name' => $tenantName,
                    'code' => $tenantCode,
                    'email'=> $tenantEmail,
                    'enableStatusesSystem' => "false",
                    'enableSkosXl' => "false"
                )
            );
            $tenant->ensureMetadata();

            insertResource($tenantManager, $tenant);

            $model = new OpenSKOS_Db_Table_MaxNumericNotation();
            $model->createRow(array(
                'tenant_code' => $tenantCode,
                'max_numeric_notation' => 1
            ))->save();

        } catch (Zend_Db_Exception $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(2);
        }
        $model = new OpenSKOS_Db_Table_Users();
        $model->createRow(array(
            'email' => $tenantEmail,
            'name' => $tenantName,
            'password' => new Zend_Db_Expr('MD5(' . $model->getAdapter()->quote($password) . ')'),
            'tenant' => $tenantCode,
            'type' => OpenSKOS_Db_Table_Users::USER_TYPE_BOTH,
            'role' => OpenSKOS_Db_Table_Users::USER_ROLE_ADMINISTRATOR,
        ))->save();
        fwrite(STDOUT, 'A tenant has been created with this user account:' . "\n");
        fwrite(STDOUT, "  - code: {$OPTS->code}\n");
        fwrite(STDOUT, "  - login: {$OPTS->email}\n");
        fwrite(STDOUT, "  - password: {$password}\n");
        break;

    case 'delete':
        /*
        $tenant = $model->find($OPTS->code)->current();
        if (null === $tenant) {
            fwrite(STDERR, "Institution `{$OPTS->code} does not exists\n");
            exit(2);
        }
        $tenant->delete();
        */
        break;
    default:
        fwrite(STDERR, "unkown action `{$action}`\n");
        exit(1);
}


exit(0);
