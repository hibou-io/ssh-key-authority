#!/usr/bin/php
<?php
##
## Copyright 2013-2017 Opera Software AS
##
## Licensed under the Apache License, Version 2.0 (the "License");
## you may not use this file except in compliance with the License.
## You may obtain a copy of the License at
##
## http://www.apache.org/licenses/LICENSE-2.0
##
## Unless required by applicable law or agreed to in writing, software
## distributed under the License is distributed on an "AS IS" BASIS,
## WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
## See the License for the specific language governing permissions and
## limitations under the License.
##

chdir(__DIR__);
require('../core.php');
require('sync-common.php');
$required_files = array('config/keys-sync', 'config/keys-sync.pub');
foreach($required_files as $file) {
	if(!file_exists($file)) die("Sync cannot start - $file not found.\n");
}

// Parse the command-line arguments
$options = getopt('h:i:au:p', array('help', 'host:', 'id:', 'all', 'user:', 'preview'));
if(isset($options['help'])) {
	show_help();
	exit(0);
}
$short_to_long = array(
	'h' => 'host',
	'i' => 'id',
	'a' => 'all',
	'u' => 'user',
	'p' => 'preview'
);
foreach($short_to_long as $short => $long) {
	if(isset($options[$short]) && isset($options[$long])) {
		echo "Error: short form -$short and long form --$long both specified\n";
		show_help();
		exit(1);
	}
	if(isset($options[$short])) $options[$long] = $options[$short];
}
$hostopts = 0;
if(isset($options['host'])) $hostopts++;
if(isset($options['id'])) $hostopts++;
if(isset($options['all'])) $hostopts++;
if($hostopts != 1) {
	echo "Error: must specify exactly one of --host, --id, or --all\n";
	show_help();
	exit(1);
}
if(isset($options['user'])) {
	$username = $options['user'];
} else {
	$username = null;
}
$preview = isset($options['preview']);

// Use 'keys-sync' user as the active user (create if it does not yet exist)
try {
	$active_user = $user_dir->get_user_by_uid('keys-sync');
} catch(UserNotFoundException $e) {
	$active_user = new User;
	$active_user->uid = 'keys-sync';
	$active_user->name = 'Synchronization script';
	$active_user->active = 1;
	$active_user->admin = 1;
	$active_user->developer = 0;
	$user_dir->add_user($active_user);
}


// Build list of servers to sync
if(isset($options['all'])) {
	$servers = $server_dir->list_servers();
} elseif(isset($options['host'])) {
	$servers = array();
	$hostnames = explode(",", $options['host']);
	foreach($hostnames as $hostname) {
		$hostname = trim($hostname);
		try {
			$servers[] = $server_dir->get_server_by_hostname($hostname);
		} catch(ServerNotFoundException $e) {
			echo "Error: hostname '$hostname' not found\n";
			exit(1);
		}
	}
} elseif(isset($options['id'])) {
	sync_server($options['id'], $username, $preview);
	exit(0);
}

$pending_syncs = array();
foreach($servers as $server) {
	if($server->key_management != 'keys') {
		continue;
	}
	$pending_syncs[$server->hostname] = $server;
}

$sync_procs = array();
define('MAX_PROCS', 20);
while(count($sync_procs) > 0 || count($pending_syncs) > 0) {
	while(count($sync_procs) < MAX_PROCS && count($pending_syncs) > 0) {
		$server = reset($pending_syncs);
		$hostname = key($pending_syncs);
		$args = array();
		$args[] = '--id';
		$args[] = $server->id;
		if(!is_null($username)) {
			$args[] = '--user';
			$args[] = $username;
		}
		if($preview) {
			$args[] = '--preview';
		}
		$sync_procs[] = new SyncProcess(__FILE__, $args);
		unset($pending_syncs[$hostname]);
	}
	foreach($sync_procs as $ref => $sync_proc) {
		$data = $sync_proc->get_data();
		if(!empty($data)) {
			echo $data['output'];
			unset($sync_procs[$ref]);
		}
	}
	usleep(200000);
}

function show_help() {
?>
Usage: sync.php [OPTIONS]
Syncs public keys to the specified hosts.

Mandatory arguments to long options are mandatory for short options too.
  -a, --all              sync with all active hosts in the database
  -h, --host=HOSTNAME    sync only the specified host(s)
                         (specified by name, comma-separated)
  -i, --id=ID            sync only the specified single host
                         (specified by id)
  -u, --user             sync only the specified user account
  -p, --preview          perform no changes, display content of all
                         keyfiles
      --help             display this help and exit
<?php
}

function sync_server($id, $only_username = null, $preview = false) {
	global $config;
	global $server_dir;
	global $user_dir;

	$keydir = '/var/local/keys-sync';
	$header = "## Auto generated keys file for %s
## Do not edit this file! Modify at %s
";
	$header_no_link = "## Auto generated keys file for %s
## Do not edit this file!
";
	$ska_key = file_get_contents('config/keys-sync.pub');

	$server = $server_dir->get_server_by_id($id);
	$hostname = $server->hostname;
	echo date('c')." {$hostname}: Preparing sync.\n";
	$server->ip_address = gethostbyname($hostname);
	$server->update();
	if($server->key_management != 'keys') return;
	$accounts = $server->list_accounts();
	$keyfiles = array();
	$sync_warning = false;
	// Generate keyfiles for each account
	foreach($accounts as $account) {
		if($account->active == 0 || $account->sync_status == 'proposed') continue;
		$username = str_replace('/', '', $account->name);
		$keyfile = sprintf($header, "account '{$account->name}'", $config['web']['baseurl']."/servers/".urlencode($hostname)."/accounts/".urlencode($account->name));
		// Collect a set of all groups that the account is a member of (directly or indirectly) and the account itself
		$sets = $account->list_group_membership();
		$sets[] = $account;
		foreach($sets as $set) {
			if(get_class($set) == 'Group') {
				if($set->active == 0) continue; // Rules for inactive groups should be ignored
				$keyfile .= "# === Start of rules applied due to membership in {$set->name} group ===\n";
			}
			$access_rules = $set->list_access();
			$keyfile .= get_keys($access_rules, $account->name, $hostname);
			if(get_class($set) == 'Group') {
				$keyfile .= "# === End of rules applied due to membership in {$set->name} group ===\n\n";
			}
		}
		$keyfiles[$username] = array('keyfile' => $keyfile, 'check' => false, 'account' => $account);
	}
	if($server->authorization == 'automatic LDAP' || $server->authorization == 'manual LDAP') {
		// Generate keyfiles for LDAP users
		$optiontext = array();
		foreach($server->list_ldap_access_options() as $option) {
			$optiontext[] = $option->option.(is_null($option->value) ? '' : '="'.str_replace('"', '\\"', $option->value).'"');
		}
		$prefix = implode(',', $optiontext);
		if($prefix !== '') $prefix .= ' ';
		$users = $user_dir->list_users();
		foreach($users as $user) {
			$username = str_replace('/', '', $user->uid);
			if(is_null($only_username) || $username == $only_username) {
				if(!isset($keyfiles[$username])) {
					$keyfile = sprintf($header, "LDAP user '{$user->uid}'", $config['web']['baseurl']);
					$keys = $user->list_public_keys($username, $hostname);
					if(count($keys) > 0) {
						if($user->active) {
							foreach($keys as $key) {
								$keyfile .= $prefix.$key->export()."\n";
							}
						} else {
							$keyfile .= "# Inactive account\n";
						}
						$keyfiles[$username] = array('keyfile' => $keyfile, 'check' => ($server->authorization == 'manual LDAP'));
					}
				}
			}
		}
	}
	if(array_key_exists('keys-sync', $keyfiles)) {
		// keys-sync account should never be synced
		unset($keyfiles['keys-sync']);
	}
	if($preview) {
		foreach($keyfiles as $username => $keyfile) {
			echo date('c')." {$hostname}: account '$username':\n\n\033[1;34m{$keyfile['keyfile']}\033[0m\n\n";
		}
		return;
	}
	// IP address check
	echo date('c')." {$hostname}: Checking IP address {$server->ip_address}.\n";
	$matching_servers = $server_dir->list_servers(array(), array('ip_address' => $server->ip_address, 'key_management' => array('keys')));
	if(count($matching_servers) > 1) {
		echo date('c')." {$hostname}: Multiple hosts with same IP address.\n";
		$server->sync_report('sync failure', 'Multiple hosts with same IP address');
		$server->delete_all_sync_requests();
		report_all_accounts_failed($keyfiles);
		return;
	}

	// This is working around deficiencies in the ssh2 library. In some cases, ssh connection attempts will fail, and
	// the socket timeout of 60 seconds is somehow not triggered. Script execution timeout is also not triggered.
	// Reproducing this problem is not easy - dropping packets to port 22 is not sufficient (it will timeout correctly).
	// To workaround, we wrap calls to this script with 'timeout' shell command, and from this point on until we have
	// established a connection, catch SIGTERM and report server sync failure if received
	declare(ticks = 1);
	pcntl_signal(SIGTERM, function($signal) use($server, $hostname, $keyfiles) {
		echo date('c')." {$hostname}: SSH connection timed out.\n";
		$server->sync_report('sync failure', 'SSH connection timed out');
		$server->delete_all_sync_requests();
		report_all_accounts_failed($keyfiles);
		exit(1);
	});

	echo date('c')." {$hostname}: Attempting to connect.\n";
	$legacy = false;
	$attempts = array('keys-sync', 'root');
	foreach($attempts as $attempt) {
		try {
			$connection = ssh2_connect($hostname, 22);
		} catch(ErrorException $e) {
			echo date('c')." {$hostname}: Failed to connect.\n";
			$server->sync_report('sync failure', 'SSH connection failed');
			$server->delete_all_sync_requests();
			report_all_accounts_failed($keyfiles);
			return;
		}
		$fingerprint = ssh2_fingerprint($connection, SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_HEX);
		if(is_null($server->rsa_key_fingerprint)) {
			$server->rsa_key_fingerprint = $fingerprint;
			$server->update();
		} else {
			if(strcmp($server->rsa_key_fingerprint, $fingerprint) !== 0) {
				echo date('c')." {$hostname}: RSA key validation failed.\n";
				$server->sync_report('sync failure', 'SSH host key verification failed');
				$server->delete_all_sync_requests();
				report_all_accounts_failed($keyfiles);
				return;
			}
		}
		try {
			ssh2_auth_pubkey_file($connection, $attempt, 'config/keys-sync.pub', 'config/keys-sync');
			echo date('c')." {$hostname}: Logged in as $attempt.\n";
			break;
		} catch(ErrorException $e) {
			$legacy = true;
			if($attempt == 'root') {
				echo date('c')." {$hostname}: Public key authentication failed.\n";
				$server->sync_report('sync failure', 'SSH authentication failed');
				$server->delete_all_sync_requests();
				report_all_accounts_failed($keyfiles);
				return;
			}
		}
	}
	try {
		$sftp = ssh2_sftp($connection);
	} catch(ErrorException $e) {
		echo date('c')." {$hostname}: SFTP subsystem setup failed.\n";
		$server->sync_report('sync failure', 'SFTP subsystem failed');
		$server->delete_all_sync_requests();
		report_all_accounts_failed($keyfiles);
		return;
	}
	try {
		$dir = ssh2_sftp_stat($sftp, $keydir);
	} catch(ErrorException $e) {
		echo date('c')." {$hostname}: Key directory does not exist.\n";
		$dir = null;
		$sync_warning = 'Key directory does not exist';
	}
	if($legacy && !$sync_warning) {
		$sync_warning = 'Using legacy sync method';
	}

	// From this point on, catch SIGTERM and ignore. SIGINT or SIGKILL is required to stop, so timeout wrapper won't
	// cause a partial sync
	pcntl_signal(SIGTERM, SIG_IGN);

	$account_errors = 0;
	$cleanup_errors = 0;

	if($legacy && isset($keyfiles['root'])) {
		// Legacy sync (only if using root account)
		$keyfile = $keyfiles['root'];
		try {
			$local_filename = tempnam('/tmp', 'syncfile');
			$fh = fopen($local_filename, 'w');
			fwrite($fh, $keyfile['keyfile'].$ska_key);
			fclose($fh);
			ssh2_scp_send($connection, $local_filename, '/root/.ssh/authorized_keys2', 0600);
			unlink($local_filename);
			if(isset($keyfile['account'])) {
				$keyfile['account']->sync_report('sync success');
			}
		} catch(ErrorException $e) {
			echo date('c')." {$hostname}: Sync command execution failed for legacy root.\n";
			$account_errors++;
			if(isset($keyfile['account'])) {
				$keyfile['account']->sync_report('sync failure');
			}
		}
	}

	// New sync
	if($dir) {
		$stream = ssh2_exec($connection, '/usr/bin/sha1sum '.escapeshellarg($keydir).'/*');
		stream_set_blocking($stream, true);
		$entries = explode("\n", stream_get_contents($stream));
		$sha1sums = array();
		foreach($entries as $entry) {
			if(preg_match('|^([0-9a-f]{40})  '.preg_quote($keydir, '|').'/(.*)$|', $entry, $matches)) {
				$sha1sums[$matches[2]] = $matches[1];
			}
		}
		fclose($stream);
		foreach($keyfiles as $username => $keyfile) {
			if(is_null($only_username) || $username == $only_username) {
				try {
					$remote_filename = "$keydir/$username";
					$remote_entity = "ssh2.sftp://$sftp$remote_filename";
					$create = true;
					if($keyfile['check']) {
						$stream = ssh2_exec($connection, 'id '.escapeshellarg($username));
						stream_set_blocking($stream, 1);
						$output = stream_get_contents($stream);
						fclose($stream);
						if(empty($output)) $create = false;
					}
					if($create) {
						if(isset($sha1sums[$username]) && $sha1sums[$username] == sha1($keyfile['keyfile'])) {
							echo date('c')." {$hostname}: No changes required for {$username}\n";
						} else {
							file_put_contents($remote_entity, $keyfile['keyfile']);
							ssh2_exec($connection, 'chown keys-sync: '.escapeshellarg($remote_filename));
							echo date('c')." {$hostname}: Updated {$username}\n";
						}
						if(isset($sha1sums[$username])) {
							unset($sha1sums[$username]);
						}
					} else {
						ssh2_sftp_unlink($sftp, $remote_filename);
					}
					if(isset($keyfile['account'])) {
						if($sync_warning && $username != 'root') {
							// File was synced, but will not work due to configuration on server
							$keyfile['account']->sync_report('sync warning');
						} else {
							$keyfile['account']->sync_report('sync success');
						}
					}
				} catch(ErrorException $e) {
					$account_errors++;
					echo "{$hostname}: Sync command execution failed for $username, ".$e->getMessage()."\n";
					if(isset($keyfile['account'])) {
						$keyfile['account']->sync_report('sync failure');
					}
				}
			}
		}
		if(is_null($only_username)) {
			// Clean up directory
			foreach($sha1sums as $file => $sha1sum) {
				if($file != '' && $file != 'keys-sync') {
					try {
						if(ssh2_sftp_unlink($sftp, "$keydir/$file")) {
							echo date('c')." {$hostname}: Removed unknown file: {$file}\n";
						} else {
							$cleanup_errors++;
							echo date('c')." {$hostname}: Couldn't remove unknown file: {$file}\n";
						}
					} catch(ErrorException $e) {
						$cleanup_errors++;
						echo date('c')." {$hostname}: Couldn't remove unknown file: {$file}, ".$e->getMessage().".\n";
					}
				}
			}
		}
	}
	try {
		$uuid = trim(file_get_contents("ssh2.sftp://$sftp/etc/uuid"));
		$server->uuid = $uuid;
		$server->update();
	} catch(ErrorException $e) {
		// If the /etc/uuid file does not exist, silently ignore
	}
	if($cleanup_errors > 0) {
		$server->sync_report('sync failure', 'Failed to clean up '.$cleanup_errors.' file'.($cleanup_errors == 1 ? '' : 's'));
	} elseif($account_errors > 0) {
		$server->sync_report('sync failure', $account_errors.' account'.($account_errors == 1 ? '' : 's').' failed to sync');
	} elseif($sync_warning) {
		$server->sync_report('sync warning', $sync_warning);
	} else {
		$server->sync_report('sync success', 'Synced successfully');
	}
	echo date('c')." {$hostname}: Sync finished\n";
}

function get_keys($access_rules, $account_name, $hostname) {
	$keyfile = '';
	foreach($access_rules as $access) {
		$grant_date = new DateTime($access->grant_date);
		$grant_date_full = $grant_date->format('c');
		$entity = $access->source_entity;
		$optiontext = array();
		foreach($access->list_options() as $option) {
			$optiontext[] = $option->option.(is_null($option->value) ? '' : '="'.str_replace('"', '\\"', $option->value).'"');
		}
		$prefix = implode(',', $optiontext);
		if($prefix !== '') $prefix .= ' ';
		switch(get_class($entity)) {
		case 'User':
			$keyfile .= "# {$entity->uid}";
			$keyfile .= " granted access by {$access->granted_by->uid} on {$grant_date_full}";
			$keyfile .= "\n";
			if($entity->active) {
				$keys = $entity->list_public_keys($account_name, $hostname);
				foreach($keys as $key) {
					$keyfile .= $prefix.$key->export()."\n";
				}
			} else {
				$keyfile .= "# Inactive account\n";
			}
			break;
		case 'ServerAccount':
			$keyfile .= "# {$entity->name}@{$entity->server->hostname}";
			$keyfile .= " granted access by {$access->granted_by->uid} on {$grant_date_full}";
			$keyfile .= "\n";
			if($entity->server->key_management != 'decommissioned') {
				$keys = $entity->list_public_keys($account_name, $hostname);
				foreach($keys as $key) {
					$keyfile .= $prefix.$key->export()."\n";
				}
			} else {
				$keyfile .= "# Decommissioned server\n";
			}
			break;
		case 'Group':
			// Recurse!
			$seen = array($entity->name => true);
			$keyfile .= "# {$entity->name} group";
			$keyfile .= " granted access by {$access->granted_by->uid} on {$grant_date_full}";
			$keyfile .= "\n";
			if($entity->active) {
				$keyfile .= "# == Start of {$entity->name} group members ==\n";
				$keyfile .= get_group_keys($entity->list_members(), $account_name, $hostname, $prefix, $seen);
				$keyfile .= "# == End of {$entity->name} group members ==\n";
			} else {
				$keyfile .= "# Inactive group\n";
			}
			break;
		}
	}
	return $keyfile;
}

function get_group_keys($entities, $account_name, $hostname, $prefix, &$seen) {
	$keyfile = '';
	foreach($entities as $entity) {
		switch(get_class($entity)) {
		case 'User':
			$keyfile .= "# {$entity->uid}";
			$keyfile .= "\n";
			if($entity->active) {
				$keys = $entity->list_public_keys($account_name, $hostname);
				foreach($keys as $key) {
					$keyfile .= $prefix.$key->export()."\n";
				}
			} else {
				$keyfile .= "# Inactive account\n";
			}
			break;
		case 'ServerAccount':
			$keyfile .= "# {$entity->name}@{$entity->server->hostname}";
			$keyfile .= "\n";
			if($entity->server->key_management != 'decommissioned') {
				$keys = $entity->list_public_keys($account_name, $hostname);
				foreach($keys as $key) {
					$keyfile .= $prefix.$key->export()."\n";
				}
			} else {
				$keyfile .= "# Decommissioned server\n";
			}
			break;
		case 'Group':
			// Recurse!
			if(!isset($seen[$entity->name])) {
				$seen[$entity->name] = true;
				$keyfile .= "# {$entity->name} group";
				$keyfile .= "\n";
				$keyfile .= "# == Start of {$entity->name} group members ==\n";
				$keyfile .= get_group_keys($entity->list_members(), $account_name, $hostname, $prefix, $seen);
				$keyfile .= "# == End of {$entity->name} group members ==\n";
			}
			break;
		}
	}
	return $keyfile;
}

function report_all_accounts_failed($keyfiles) {
	foreach($keyfiles as $keyfile) {
		if(isset($keyfile['account'])) {
			$keyfile['account']->sync_report('sync failure');
		}
	}
}
