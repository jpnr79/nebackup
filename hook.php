<?php

/*
 * Copyright (C) 2016 Javier Samaniego García <jsamaniegog@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

// Hook called on profile change
// Good place to evaluate the user right on this plugin
// And to save it in the session
function plugin_change_profile_nebackup() {
    // For example : same right of computer
    if (Session::haveRight('networkequipment', 'w')) {
        $_SESSION["glpi_plugin_nebackup_profile"] = array('nebackup' => 'w');
    } else if (Session::haveRight('networkequipment', 'r')) {
        $_SESSION["glpi_plugin_nebackup_profile"] = array('nebackup' => 'r');
    } else {
        unset($_SESSION["glpi_plugin_nebackup_profile"]);
    }
}

/**
 * Fonction d'installation du plugin
 * @return boolean
 */
function plugin_nebackup_install() {
    global $DB;

    try {
        // Création de la table entities
        if (!$DB->tableExists("glpi_plugin_nebackup_entities")) {
            $DB->query("CREATE TABLE `glpi_plugin_nebackup_entities` (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `entities_id` BIGINT UNSIGNED NOT NULL UNIQUE,
                `tftp_server` varchar(255) NOT NULL DEFAULT '',
                `tftp_passwd` varchar(255) NOT NULL DEFAULT '',
                `telnet_passwd` varchar(255) NOT NULL DEFAULT '',
                `is_recursive` tinyint(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Création de la table configs
        if (!$DB->tableExists("glpi_plugin_nebackup_configs")) {
            $DB->query("CREATE TABLE `glpi_plugin_nebackup_configs` (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `type` varchar(255) NOT NULL DEFAULT '' UNIQUE,
                `value` longtext NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Insert default configurations
            $DB->insert('glpi_plugin_nebackup_configs', [
                'type' => 'backup_path',
                'value' => 'backup/{entity}'
            ]);
            $DB->insert('glpi_plugin_nebackup_configs', [
                'type' => 'use_fusioninventory',
                'value' => '0'
            ]);
            $DB->insert('glpi_plugin_nebackup_configs', [
                'type' => 'timeout',
                'value' => '60'
            ]);
        }

        // Création de la table logs
        if (!$DB->tableExists("glpi_plugin_nebackup_logs")) {
            $DB->query("CREATE TABLE `glpi_plugin_nebackup_logs` (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `networkequipments_id` BIGINT UNSIGNED NOT NULL,
                `date` TIMESTAMP NULL DEFAULT NULL,
                `error` longtext NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Création de la table networkequipments
        if (!$DB->tableExists("glpi_plugin_nebackup_networkequipments")) {
            $DB->query("CREATE TABLE `glpi_plugin_nebackup_networkequipments` (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `networkequipments_id` BIGINT UNSIGNED NOT NULL,
                `plugin_fusioninventory_configsecurities_id` BIGINT UNSIGNED,
                `created_at` TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        return true;
    } catch (Exception $e) {
        error_log("NEBackup install error: " . $e->getMessage());
        return false;
    }
}

/**
 * Fonction de désinstallation du plugin
 * @return boolean
 */
function plugin_nebackup_uninstall() {
    global $DB;

    $tables = array(
        "glpi_plugin_nebackup_configs",
        "glpi_plugin_nebackup_entities",
        "glpi_plugin_nebackup_networkequipments",
        "glpi_plugin_nebackup_logs"
    );

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->query("DROP TABLE IF EXISTS `" . $table . "`");
        }
    }

    // delete notifications
    $n_template = new NotificationTemplate();
    $template = $n_template->find(["name" => "NEBackup errors"]);
    if (is_array($template) && !empty($template)) {
        $template = array_values($template);

        $n_templatetranslations = new NotificationTemplateTranslation();
        $translation = $n_templatetranslations->find(["notificationtemplates_id" => $template[0]['id']]);
        if (is_array($translation) && !empty($translation)) {
            $translation = array_values($translation);
            $n_templatetranslations->delete(array('id' => $translation[0]['id']));
        }

        $n_template->delete(array('id' => $template[0]['id']));
    }

    $notification = new Notification();
    $notif = $notification->find(["name" => "NEBackup errors"]);
    if (is_array($notif) && !empty($notif)) {
        $notif = array_values($notif);
        $notification->delete(array('id' => $notif[0]['id']));
    }

    return true;
}

/**
 * Action when an item is purged.
 * @param type $params
 */
function plugin_item_purge_nebackup($params) {
    switch ($params::$rightname) {
        // delete the entity configuration and sub entities
        case 'entity':
            $config = new PluginNebackupEntity();
            $data = array_values($config->find(["entities_id" => $params->getID()]));  // search plugin entities id
            $config->setEntityData(array(
                'id' => $data[0]['id'],
                'purge' => true
            ));
            break;
    }
}

/**
 * Add massive actions to GLPI itemtypes
 *
 * @param string $type
 * @return array
 */
function plugin_nebackup_MassiveActions($type) {
    $ma = array();

    switch ($type) {
        case "NetworkEquipment":
            if (Session::haveRight('networking', UPDATE)) {
                $ma["PluginNebackupNetworkequipment" . MassiveAction::CLASS_ACTION_SEPARATOR . "assignAuth"] = __('NEBackup - SNMP auth (R/W)', 'nebackup');
                $ma["PluginNebackupNetworkequipment" . MassiveAction::CLASS_ACTION_SEPARATOR . "backup"] = __('NEBackup - Backup', 'nebackup');
            }

            break;
    }

    return $ma;
}

/**
 * Return the subject of a notification template.
 * @param type $template
 * @return string Template.
 */
function getTemplateSubject($template) {
    switch ($template) {
        case "errors":
            return "##nebackup.errors.subject##";
            break;

        default:
            return "";
            break;
    }
}

/**
 * Return the content of a notification template.
 * @param type $template
 * @param bool $html if it's true return content_html, else content_text (default)
 * @return string Template.
 */
function getTemplateContent($template, $html = false) {
    switch ($template) {
        case "errors":
            if ($html) {
                return "&lt;p&gt;##FOREACHnebackup.errors##&lt;br"
                    . " /&gt; ##lang.nebackup.networkequipment_name## ##nebackup.networkequipment_name## ##nebackup.url##&lt;br"
                    . " /&gt; ##lang.nebackup.error## ##nebackup.error##&lt;br"
                    . " /&gt; ##lang.nebackup.lastcopy## ##nebackup.lastcopy##&lt;/p&gt;"
                    . "&lt;p&gt;##ENDFOREACHnebackup.errors##&lt;/p&gt;";
            }
            return "##FOREACHnebackup.errors##"
                . "\n ##lang.nebackup.networkequipment_name## ##nebackup.networkequipment_name## ##nebackup.url##"
                . "\n ##lang.nebackup.error## ##nebackup.error##"
                . "\n ##lang.nebackup.lastcopy## ##nebackup.lastcopy##"
                . "##ENDFOREACHnebackup.errors##";
            break;

        default:
            return "";
            break;
    }
}

?>
