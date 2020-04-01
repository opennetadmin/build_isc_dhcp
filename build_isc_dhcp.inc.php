<?php

// Lets do some initial install related stuff
if (file_exists(dirname(__FILE__)."/install.php")) {
    printmsg("DEBUG => Found install file for ".basename(dirname(__FILE__))." plugin.", 1);
    include(dirname(__FILE__)."/install.php");
} else {

// Place initial popupwindow content here if this plugin uses one.


}

// Make sure we have necessary functions & DB connectivity
require_once($conf['inc_functions_db']);

//////////////////////////////////////////////////////////////////////
//  Function: build_global($server_id=0)
//
//  ** Private function used by build_dhcpd_conf() **
//
//  Builds the proper statement for global configurations
///////////////////////////////////////////////////////////////////////
function build_global($server_id=0) {
    global $onadb;
    global $dhcp_entry_options;

    // print the opening host comment with row count
    $out_text  = "\n# SERVER LEVEL DHCP OPTIONS\n# (Unique merge of global and server level options, server overrides global.)\n\n";

    list($status, $rows, $dhcp_entries) = db_get_records($onadb, 'dhcp_option_entries', "
host_id = 0
and subnet_id = 0
and server_id = 0
and dhcp_option_id not in ( select dhcp_option_id from dhcp_option_entries where server_id = {$server_id})
union
SELECT * FROM dhcp_option_entries
WHERE host_id = 0
and subnet_id = 0
and server_id = {$server_id}", '');

    printmsg('DEBUG => build_global() Processing global options.', 5);
    //process the entries
    if ($rows) {
        foreach ($dhcp_entries as $entry) {
            list($status, $rows, $dhcp_type) = ona_get_dhcp_option_entry_record(array('id' => $entry['id']));
            foreach(array_keys($dhcp_type) as $key) { $dhcp_type[$key] = htmlentities($dhcp_type[$key], ENT_QUOTES); }

            // If our dhcp option number is over 100, assume we need to create the statement for it.
            // FIXME: MP: for now this is an arbitrary assumption that most standard options are below 100
            //        In the future some exceptions may have to be made here but for now.............
            // The following is a list of the option types
            // "L"=> "IP Address List"
            // "S"=> "String"
            // "N"=> "Numeric"
            // "I"=> "IP Address"
            // "B"=> "Boolean"
            $optionformat = array("L" => "array of ip-address", "S" => "string", "N" => "integer", "I" => "ip-address", "B" => "boolean");
            if ($dhcp_type['number'] > 100) {
                $out_text .= "option {$dhcp_type['name']} code {$dhcp_type['number']} = ". $optionformat[$dhcp_type['type']] .";\n";
            }

            // format the tag appropriatly
            list($status, $formatted_entry) = format_tag($dhcp_type);


            // check that if we are to use an integer type, that the value is really an integer
            if ($dhcp_type['type'] == 'N') {
                if (!is_numeric($formatted_entry)) {
                   printmsg("DEBUG => build_global() The option {$dhcp_type['name']} is not a numeric value.", 5);
                   $out_text .= "### option {$dhcp_type['name']} {$formatted_entry};  #ERROR: value should be numeric.\n";
                   continue;
                }
            }

            if ($formatted_entry) {
                $out_text .= "option {$dhcp_type['name']} {$formatted_entry};\n";
            }
        }
    }

    // print some extra space
    $out_text .= "\n";

    return(array($exit, $out_text));
}



///////////////////////////////////////////////////////////////////////
//  Function: process_dhcp_pool(array $pool, $indent=0)
//
//  ** Private function used by build_dhcpd_conf() **
//
//  Builds the proper statement for a dhcp pool
///////////////////////////////////////////////////////////////////////
function process_dhcp_pool($pool=array(), $indent=0) {
    printmsg("DEBUG => process_dhcp_pool(\$pool, $indent) called", 5);

    // Validate input
    if (! (is_array($pool) and (count($pool) > 0)) ) {
        return(array(1, ""));
    }

    // set the indent info if required
    $dent = '';
    if ($indent != 0) {
        $dent = '    ';
    }

    $out_text  = "{$dent}    pool {\n";
    $out_text .= "{$dent}        range ". long2ip($pool['ip_addr_start']) ." " . long2ip($pool['ip_addr_end']) .";\n";
    $out_text .= "{$dent}        default-lease-time {$pool['lease_length']};\n";
    $out_text .= "{$dent}        max-lease-time {$pool['lease_length']};\n";

    // if there is a failover group, set that info
    if ($pool['dhcp_failover_group_id']) {
        $out_text .= "{$dent}        deny dynamic bootp clients;\n";
        $out_text .= "{$dent}        failover peer \"GROUP_ID-{$pool['dhcp_failover_group_id']}\";\n";
    }

    // close out the pool section
    $out_text  .= "{$dent}    }\n";

    return(array(0,$out_text));
}









///////////////////////////////////////////////////////////////////////
//  Function: get_server_name_ip(int $server_id)
//
//  ** Private function used by build_dhcpd_conf() **
//
//  Takes a server ID and returns a two part array of that server's
//  primary dns name and primary ip address.
///////////////////////////////////////////////////////////////////////
function get_server_name_ip($server_id) {
    printmsg("DEBUG => get_server_name_ip(\$server_id = $server_id) called", 5);
    list($status, $rows, $host)      = ona_get_host_record(array('id' => $server_id));
    list($status, $rows, $interface) = ona_get_interface_record(array('host_id' => $server_id));
    return(array($host['fqdn'], $interface['ip_addr']));
}







///////////////////////////////////////////////////////////////////////
//  Function: format_tag (string $dhcp_entry=array())
//
//  ** Private function used by build_dhcpd_conf() **
//
//  Input Options:
//    $dhcp_entry = an array containing information from ona_get_dhcp_option_entry_record()
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual piece of a configuration file
//
//  Example: list($status, $formatted_entry) = format_tag($dhcp_entry);
///////////////////////////////////////////////////////////////////////
function format_tag ($dhcp_entry=array()) {

    // The following is a list of the option types
    // "L"=> "IP Address List"
    // "S"=> "String"
    // "N"=> "Numeric"
    // "I"=> "IP Address"
    // "B"=> "Boolean"

    // Format $dhcp_entry['value'] based on $dhcp_entry['type']
    $formatted_entry = "";
    if ($dhcp_entry['type'] == 'L') {
        // if there are no commas, replace the whitespace with a comma.
        $pos = strpos($dhcp_entry['value'], ',');
        if ($pos === false) {
            $formatted_entry = preg_replace('/\s\s+/', ', ', ltrim($dhcp_entry['value']));
        }
        else {
            $formatted_entry = ltrim($dhcp_entry['value']);
        }
    }
    // Special case for domain-search
    else if ($dhcp_entry['name'] == 'domain-search') {
      // clean comma space
      $dhcp_entry['value'] = preg_replace('/,\s+/', ',', ltrim($dhcp_entry['value']));
      // clean space comma
      $dhcp_entry['value'] = preg_replace('/\s,+/', ',', ltrim($dhcp_entry['value']));
      // clean just a comma
      $dhcp_entry['value'] = preg_replace('/,+/', '","', ltrim($dhcp_entry['value']));
      // clean all whitespace and wrap final quotes around it all
      $formatted_entry = '"' . preg_replace('/\s+/', '","', ltrim($dhcp_entry['value'])) . '"';
    }
    else if ($dhcp_entry['type'] == 'S') {
        // If it is a string then quote it
        $formatted_entry = '"' . $dhcp_entry['value'] . '"';
    }
    // Pretty much everything else is just left alone
    else if ($dhcp_entry['type'] == 'I' or
             $dhcp_entry['type'] == 'N' or
             $dhcp_entry['type'] == 'B') {
        $formatted_entry = $dhcp_entry['value'];
    }
    else {
        printmsg("WARNING => format_tag() Found unknown tag_type: {$dhcp_entry['type']} {$dhcp_entry['value']}", 1);
    }

    return(array(0,$formatted_entry));
}








///////////////////////////////////////////////////////////////////////
//  Function: subnet_conf (string $subnet_id='',$indent=0)
//
//  ** Private function used by build_dhcpd_conf() **
//
//  Input Options:
//    $subnet = a subnet record returned by ona_get_network_record()
//    $indent = tell the function if it should indent the output or not
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual piece of a configuration file
//
//  Example: list($status, $text) = subnet_conf($subnet_record, 1);
///////////////////////////////////////////////////////////////////////
function subnet_conf ($subnet=array(), $indent=0) {
    printmsg("DEBUG => subnet_conf(\$subnet, $indent) called", 5);
   // global $dhcp_entry_options;
    global $self;
    $exit = 0;

    // Validate input
    if (! (is_array($subnet) and (count($subnet) > 0)) ) {
        return(array(1, ""));
    }

    // set the indent info if required
    $dent = '';
    if ($indent != 0) {
        $dent = '    ';
    }

    $text   = "\n{$dent}# {$subnet['name']}\n";
    // Determine if this is a IPv6 address
    if ($subnet['ip_addr'] > '4294967295') {
        $text  .= "{$dent}subnet6 ". ip_mangle($subnet['ip_addr'], 'ipv6') ."/".ip_mangle($subnet['ip_mask'], 'cidr')." {\n";
        // v6 does not allow a gateway defined, it uses the RA to do it.
        $hasgatewayoption = 1;
        $v6option = 'dhcp6.';
    } else {
        $text  .= "{$dent}subnet ". long2ip($subnet['ip_addr']) ." netmask ".long2ip($subnet['ip_mask'])." {\n";
        $hasgatewayoption = 0;
        $v6option = '';
    }

    // Loop through all of the dhcp entries and print them
    $i = 0;
    do {
        list($status, $rows, $dhcp_entry) = ona_get_dhcp_option_entry_record(array('subnet_id' => $subnet['id']));
        printmsg("DEBUG => subnet_conf(): Processing option {$dhcp_entry['display_name']}", 3);
        if (!$rows) { break; }
        if ($status) { $exit++; break; }
        // if the current dhcp entry is the "Default Gateway" option then set hasgatewayoption to 1
        if (strpos($dhcp_entry['name'], 'router') !== false) {printmsg("DEBUG => subnet_conf(\$subnet, $indent): --------.", 5);$hasgatewayoption = 1;}
        $i++;

        // format the tag appropriatly
        list($status, $formatted_entry) = format_tag($dhcp_entry);

        if ($formatted_entry) {
            $text .= "{$dent}    option {$v6option}{$dhcp_entry['name']} {$formatted_entry};\n";
        } else { $exit++; break; }
    } while ($i < $rows);

    // Loop through all of the dhcp pools and print them
    $i = 0;
    do {
        list($status, $poolrows, $pool) = ona_get_dhcp_pool_record(array('subnet_id' => $subnet['id']));
        if (!$rows) { break; }
        if ($status) { $exit++; break; }
        $i++;

        list($status, $srows, $server_subnet) = ona_get_dhcp_server_subnet_record(array('subnet_id' => $pool['subnet_id']));

        // if there is no failover group assignment and this pool is related to your server, print it
        if ($server_subnet['host_id'] == $self['serverid'] && $pool['dhcp_failover_group_id'] == 0) {
            printmsg("DEBUG => subnet_conf(\$subnet, $indent): Found pool with no failovergroup.", 5);
            list($status, $pool_entry) = process_dhcp_pool($pool, $indent);
            $text .= $pool_entry;
        }

        // if there is a failover group assignment, print the pool
        if ($pool['dhcp_failover_group_id'] != 0) {
            printmsg("DEBUG => subnet_conf(\$subnet, $indent): Found pool with a failovergroup", 5);
            list($status, $pool_entry) = process_dhcp_pool($pool, $indent);
            $text .= $pool_entry;
        }


    } while ($i < $poolrows);

    // Close the subnet block
    $text .= "{$dent}}\n";

    // Return the subnet block if there is a gateway option defined.
    if ($hasgatewayoption == 1) {
        return(array($exit, $text));
    }
    else {
        printmsg("ERROR => subnet_conf({$subnet['name']}): Not enabling subnet, no gateway option defined. ", 0);
        $text = "\n{$dent}# WARNING => Subnet {$subnet['name']} has no default gateway opiton defined, skipping...\n";
        return(array($exit, $text));
    }

}









///////////////////////////////////////////////////////////////////////
//  Function: ona_dhcp_build_failover_group (string $server_id='')
//
//  ** Private function used by build_dhcpd_conf()
//
//  Input Options:
//    $server = the server id for the server
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. The configuration code for the failover groups
//
///////////////////////////////////////////////////////////////////////
function ona_dhcp_build_failover_group($server_id=0) {
    printmsg("DEBUG => ona_dhcp_build_failover_group($server_id) called", 5);

    // Validate input
    if ($server_id == 0) {
        return(array(1, ""));
    }

    $field =  array('primary'   => 'primary_server_id',
                    'secondary' => 'secondary_server_id');

    // Define the two modes that failover could be in, and loop through (both of) them
    foreach (array('primary', 'secondary') as $mode) {

        // Loop through the records
        $i = 0;
        do {

            // Get a record from the db
            list($status, $rows, $record) = ona_get_dhcp_failover_group_record(array($field[$mode] => $server_id));
            $i++;

            // If there were no records, exit the loop
            if ($rows == 0) { break; }

            // print the first section info
            if ($i == 1 ) $text = "# --------FAILOVER GROUPS (count={$rows})--------\n\n";

            // call the function to get the dns name and ip address of each server
            list($pri_name, $pri_ip) = get_server_name_ip($record['primary_server_id']);
            list($sec_name, $sec_ip) = get_server_name_ip($record['secondary_server_id']);

            // begin printing failover statemens
            $text .= "# {$pri_name} (Primary) to {$sec_name} (Secondary)\n";
            $text .= "failover peer \"GROUP_ID-{$record['id']}\" {\n";
            $text .= "  {$mode};\n";

            if ($mode == "primary") {
                $text .= "  address " . long2ip($pri_ip) . ";\n";
                $text .= "  port {$record['primary_port']};\n";
                $text .= "  peer address " . long2ip($sec_ip) . ";\n";
                $text .= "  peer port {$record['peer_port']};\n";
                $text .= "  mclt {$record['mclt']};\n";
                $text .= "  split {$record['split']};\n";
            }
            if ($mode == "secondary") {
                $text .= "  address " . long2ip($sec_ip) . ";\n";
                $text .= "  port {$record['peer_port']};\n";
                $text .= "  peer address " . long2ip($pri_ip) . ";\n";
                $text .= "  peer port {$record['primary_port']};\n";
            }
            $text .= "  max-response-delay {$record['max_response_delay']};\n";
            $text .= "  max-unacked-updates {$record['max_unacked_updates']};\n";
            $text .= "  load balance max seconds {$record['max_load_balance']};\n";
            $text .= "}\n\n";

        } while ($i < $rows);
    }

    // Return the failover group config file
    return(array(0, $text));

}






///////////////////////////////////////////////////////////////////////
//  Function: build_hosts(string $server_id='')
//
//  Input Options:
//     $server_id         The id of the server you want to build host entries for
//
//  Output:
//     array($status, $text)     Will return the status of the function as well as the textual body of text
//
//  Example: list($status, $result) = build_hosts('12445');
///////////////////////////////////////////////////////////////////////
function build_hosts($server_id=0) {
    global $self;
    global $onadb;
    global $dhcp_entry_options;

    printmsg("DEBUG => build_hosts() Processing hosts for server: {$server_id}", 3);

    // ipv6: for now we are going to skip over any v6 address space
    //       need to pass in if we want v6 or not

    // For the given server, select all host entries that have mac addresses
    // This is to build the static, mac based, host entries
    // NOTE: I use the concat and inet_ntoa functions.. not sure how portable they really are.
    $q="
        SELECT  H.id,
                concat(D.name,'.',Z.name) primary_dns_name,
                inet_ntoa(I.IP_ADDR) ip_addr,
                UPPER(I.MAC_ADDR) mac,
                B.ID dhcp_entry_id
        FROM    hosts H,
                dns D,
                domains Z,
                interfaces I LEFT OUTER JOIN dhcp_option_entries B ON I.host_id = B.host_id
        WHERE   I.mac_addr NOT like ''
        AND     I.host_id = H.id
        AND     D.domain_id = Z.id
        AND     D.id = H.primary_dns_id
        AND     (
                 ( I.subnet_id IN (
                        SELECT  subnet_id
                        FROM    dhcp_server_subnets
                        WHERE   host_id = {$server_id}
                        AND     ip_addr < 4294967295)
                 )
                 OR
                 ( I.subnet_id IN (
                        SELECT  subnet_id
                        FROM    dhcp_pools
                        WHERE   dhcp_failover_group_id IN (
                                SELECT id
                                FROM dhcp_failover_groups
                                WHERE   primary_server_id = {$server_id}
                                OR      secondary_server_id = {$server_id}))
                 )
                )
        ORDER BY I.ip_addr";




    // exectue the query
    $rs = $onadb->Execute($q);
    if ($rs === false or (!$rs->RecordCount())) {
        $self['error'] = 'ERROR => build_hosts(): SQL query failed: ' . $onadb->ErrorMsg(); 
        printmsg($self['error'], 0);
        $exit += 1;
    }
    $rows = $rs->RecordCount();

    // print the opening host comment with row count
    if ($rows) $text  = "\n# --------HOSTS (count={$rows})--------\n\n";

    // Loop through the record set
    $last_host = 0;
    while ($host = $rs->FetchRow()) {
        printmsg('DEBUG => build_host() Processing host: '. $host['primary_dns_name'], 5);

        // print closing brace only if this is a new host, AND it is not the first row
        if ($last_host != $host['ip_addr'] && $last_host != 0) { $text .= "}\n\n"; }

        if ($last_host != $host['ip_addr']) {
            $text .= "host {$host['ip_addr']} {  # {$host['primary_dns_name']}\n";

            // TODO: ipv6 may be fun here
            // https://lists.isc.org/pipermail/dhcp-users/2009-February/008463.html
            // https://lists.isc.org/pipermail/dhcp-users/2009-March/008678.html
            // http://www.ietf.org/mail-archive/web/dhcwg/current/msg12455.html
            $text .= "    fixed-address {$host['ip_addr']};\n";

            // Currently we are not supporting other hardware types available
            // tokenring and fddi are other options than ethernet here,
            // if it is needed.. use the hardware type option. possible TODO to fix this
            $text .= "    hardware ethernet " . mac_mangle($host['mac']) . ";\n";

            // hostname option does not seem to be found in the "dhcp handbook" so I'm leaving it out for now
            //$text .= "    option hostname \"{$host['fqdn']}\";\n";
        }

        // process any dhcp options
        if ($host['dhcp_entry_id']) {
            list($status, $rows, $dhcp_entry) = ona_get_dhcp_option_entry_record(array('id' => $host['dhcp_entry_id']));
            if (!$rows) { break; }
            if ($status) { $exit++; break; }

            // format the tag appropriatly
            list($status, $formatted_entry) = format_tag($dhcp_entry);

            if ($formatted_entry != '') {
                $text .= "    option {$dhcp_entry['name']} {$formatted_entry};\n";
            } else { $exit++; break; }
        }

        // increment the host anchor to determine if we are dealing with a new host in the next loop
        $last_host = $host['ip_addr'];
    } 

    // print the final closing brace
    if ($rows) {$text .= "}\n\n";}

    // close the record set
    $rs->Close();

    // return host config text
    return(array($exit, $text));
}




///////////////////////////////////////////////////////////////////////
//  Function: build_dhcpd_conf (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = build_dhcpd_conf('host=test');
///////////////////////////////////////////////////////////////////////
function build_dhcpd_conf($options="") {
    global $self;
    global $conf;
    global $onadb;

    // Version - UPDATE on every edit!
    $version = '1.10';

    // Exit status of the function
    $exit = 0;

    printmsg('DEBUG => build_dhcpd_conf('.$options.') called', 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or !$options['server']) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        return(array(1, 
<<<EOM

build_dhcpd_conf-v{$version}
Builds configuration for dhcpcd from the database

  Synopsis: build_dhcpd_conf [KEY=VALUE] ...

  Required:
    server=NAME[.DOMAIN] or ID    Build conf by hostname or HOST_ID

  Optional:
    header_path=PATH              Path to the server local header to include

  Notes:
    * Specified host must be a valid DHCP server
    * header_path is a file on the DHCP server.  It will be defined at
      the very top of your configuration using the DHCP "include" directive.
\n
EOM

        ));
    }

    // TODO: ipv6 need to pass in if we want v4 or v6.. default to v4 for now. 
    //       looks like you cant have a mixed config

    // Debugging
    printmsg("DEBUG => Building DHCP config for: {$options['server']}", 3);

    // Validate that there is already a host named $options['server'].
    list($status, $rows, $host) = ona_find_host($options['server']);

    if (!$host['id']) {
        return(array(2, "ERROR => No such host: {$options['server']}\n"));
    }

    // Now determine if that host is a valid server
    list($status, $dhcp_rows, $dhcp_server) = db_get_records($onadb, 'dhcp_server_subnets', array('host_id' => $host['id']), '');
    list($status, $dhcpf_rows, $dhcpf_server) = db_get_records($onadb, 'dhcp_failover_groups', "primary_server_id = {$host['id']} or secondary_server_id = {$host['id']}", '');
    if ($dhcp_rows == 0 and $dhcpf_rows == 0) {
        return(array(3, "ERROR => Specified host is not a DHCP server: {$options['server']}\n"));
    }

    // Throw the host id into a self variable for later use
    $self['serverid']=$host['id'];

    // Start an output variable with build timestamp
    $text .= "###### DO NOT EDIT THIS FILE ###### \n";
    $text .= "# dhcpd.conf file for {$host['fqdn']} built on " . date($conf['date_format']) . "\n#\n";
    $text .= "# This file is built by an automated script.  Any change to this \n";
    $text .= "# file will be lost at the next build.\n\n";

    // setup standard include path
    // TODO: MP possibly put this into a configuration option like header so the user can easily change where this is.
    if (isset($options['header_path'])) {
        $text .= "include \"{$options['header_path']}\";\n";
    }

    /////////////////////////////// Build global options //////////////////////////////////////////

    list($status, $globals) = build_global($host['id']);
    $text .= $globals;

    /////////////////////////////// Failover groups //////////////////////////////////////////

    // build list of failover group statements for provided server
    list($status, $failovergroup) = ona_dhcp_build_failover_group($host['id']);
    $text .= $failovergroup;

    /////////////////////////////// shared subnets //////////////////////////////////////////



    // setup a variable to keep track of which vlan we are on
    $vlananchor = '';

    // Loop through all of the vlan subnets and print them
    printmsg("DEBUG => Processing all Shared (VLAN) Subnets", 1);

    $i = 0;
    do {
        list($status, $rows, $vlan_subnet) = ona_get_record('vlan_id != 0 AND
                                                              id IN (SELECT subnet_id
                                                                     FROM   dhcp_server_subnets
                                                                     WHERE  host_id = '.$host['id'].'
                                                                     UNION
                                                                     SELECT subnet_id
                                                                     FROM dhcp_pools
                                                                     WHERE dhcp_failover_group_id IN (SELECT id
                                                                                                      FROM dhcp_failover_groups
                                                                                                      WHERE primary_server_id = '.$host['id'].'
                                                                                                      OR secondary_server_id = '.$host['id'].'))',
                                                             'subnets',
                                                             'vlan_id ASC');
        if ($status) {
            printmsg($self['error'], 0);
            $exit += $status;
        }

        if ($rows == 0) {
            printmsg("DEBUG => build_dhcpd_conf(): Found no shared subnets.", 3);
            break;
        }
        else if ($i == 0) {
            $text .= "# --------SHARED SUBNETS (count={$rows})--------\n\n";
        }

        printmsg("DEBUG => Processing vlan subnet " . ($i + 1) . " of {$rows}", 3);

        // pull info about the vlan itself
        list($status, $vlanrows, $vlan) = ona_get_vlan_record(array('id' => $vlan_subnet['vlan_id']));
        if ($status) {
            printmsg($self['error'], 0);
            $exit += $status;
        }

        // check to see if we have switched to a new vlan
        if ($vlananchor != $vlan_subnet['vlan_id']) {
            // if this is NOT the first loop through, close the previous shared network block
            if ($i >= 1) {$text .= "}\n\n";}

            // print the opening statement for the shared network block and strip characters that may cause errors
	    $text .= "shared-network " . preg_replace('/[^A-Za-z0-9_-]/', '', "{$vlan['vlan_campus_name']}-{$vlan['number']}-{$vlan['name']}") . " {\n";
        }

        // print the subnet block for the current subnet in the loop
        list($status, $subnetblock) = subnet_conf($vlan_subnet,1);
        if ($status) {
            printmsg("ERROR => subnet_conf() returned an error: vlan subnet: {$vlan_subnet['name']}", 0);
            $exit += $status;
        }
        else {
            $text .= $subnetblock;
        }

        $i++;

        // If the loop is at the end,and this isnt the first time we've come through the loop, print a close statement
        // if ($i == $rows && $vlananchor != '') {$text .= "}\n\n";}
        if ($i == $rows) {$text .= "}\n\n";}

        // continue to update the vlan anchor
        $vlananchor = $vlan_subnet['vlan_id'];
    } while ($i < $rows);


    /////////////////////////////// standard subnets //////////////////////////////////////////


    // Loop through all of the NON vlan subnets and print them
    printmsg("DEBUG => Processing all Non-Shared (Standard) Subnets", 1);

    // We do our own sql query here because it makes more sense than calling ona_get_record() a zillion times ;)
    $q = "SELECT *
          FROM subnets
          WHERE vlan_id = 0 AND
                id IN (SELECT subnet_id
                       FROM dhcp_server_subnets
                       WHERE host_id = {$host['id']}
                       UNION
                       SELECT subnet_id
                       FROM dhcp_pools
                       WHERE dhcp_failover_group_id IN (SELECT id
                                                        FROM dhcp_failover_groups
                                                        WHERE primary_server_id = {$host['id']}
                                                        OR secondary_server_id = {$host['id']}))

          ORDER BY name ASC";
    $rs = $onadb->Execute($q);
    if ($rs === false) {
        $self['error'] = 'ERROR => build_dhcpd_conf(): standard_subnets: SQL query failed: ' . $onadb->ErrorMsg(); 
        printmsg($self['error'], 0);
        $exit += 1;
    }
    $rows = $rs->RecordCount();
    if ($rows > 0) $text .= "# --------STANDARD SUBNETS (count={$rows})--------\n";

    $i = 0;
    // Loop through the record set
    while ($std_subnet = $rs->FetchRow()) {
        printmsg("DEBUG => build_dhcpd_conf() Processing standard subnet " . ($i + 1) . " of {$rows}", 3);

        // print the subnet info for the current subnet in the loop
        list($status, $subnetblock) = subnet_conf($std_subnet,0);
        if ($status) {
            printmsg("ERROR => subnet_conf() returned an error: non-vlan subnet: {$std_subnet['description']}", 0);
            $exit += $status;
        }
        else {
            $text .= $subnetblock;
        }

        $i++;
    }
    $rs->Close();

    /////////////////////////////// build static hosts //////////////////////////////////////////

    list($status, $hostconf) = build_hosts($host['id']);
    $text .= $hostconf;

    /////////////////////////////// Yer done, go home //////////////////////////////////////////
    // Return the config file
    return(array($exit, $text));

}




?>
