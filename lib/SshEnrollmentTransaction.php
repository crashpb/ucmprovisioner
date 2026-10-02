<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Staged SSH policy replacement with retained rollback through the fresh SFTP check. */
final class SshEnrollmentTransaction
{
    public static function authorizedKeysScript(string $existing,string $merged): string
    {
        $existingHash=hash('sha256',$existing);
        $emptyHash=hash('sha256','');
        $mergedHash=hash('sha256',$merged);
        $mergedB64=base64_encode($merged);
        return <<<SH
set -eu
command -v base64 >/dev/null 2>&1
command -v cmp >/dev/null 2>&1
command -v cut >/dev/null 2>&1
command -v install >/dev/null 2>&1
command -v mktemp >/dev/null 2>&1
command -v runuser >/dev/null 2>&1 || { echo 'runuser is required to verify ucmp-tftp authorized_keys access' >&2; exit 78; }
command -v sha256sum >/dev/null 2>&1 || { echo 'sha256sum is required to verify authorized_keys bytes' >&2; exit 78; }
command -v stat >/dev/null 2>&1 || { echo 'stat is required to verify authorized_keys directory ownership and mode' >&2; exit 78; }
test ! -L "\$ssh_root/authorized_keys"
if test -e "\$ssh_root/authorized_keys"; then test -d "\$ssh_root/authorized_keys"; fi
test ! -L "\$ssh_root/authorized_keys/ucmp-tftp"
if test -e "\$ssh_root/authorized_keys/ucmp-tftp"; then test -f "\$ssh_root/authorized_keys/ucmp-tftp"; fi

ucmp_check_old_authorized_keys() {
  if test -f "\$ssh_recovery/authorized_keys.before"; then
    test -f "\$ssh_root/authorized_keys/ucmp-tftp" && test ! -L "\$ssh_root/authorized_keys/ucmp-tftp" || { echo 'SSH authorized_keys disappeared or became unsafe after preflight' >&2; exit 73; }
    test "\$(sha256sum "\$ssh_root/authorized_keys/ucmp-tftp" | cut -d' ' -f1)" = '$existingHash' || { echo 'SSH authorized_keys changed after preflight; refusing key publication' >&2; exit 73; }
  elif test -f "\$ssh_recovery/authorized_keys.absent"; then
    test '$existingHash' = '$emptyHash' || { echo 'authorized_keys disappeared after preflight; refusing to replace concurrent enrollment' >&2; exit 74; }
    test ! -e "\$ssh_root/authorized_keys/ucmp-tftp" && test ! -L "\$ssh_root/authorized_keys/ucmp-tftp" || { echo 'SSH authorized_keys appeared after an absent preflight' >&2; exit 74; }
  else
    echo 'SSH enrollment key preflight backup is missing' >&2
    exit 73
  fi
}

ucmp_check_old_authorized_keys
test ! -e "\$ssh_recovery/authorized_keys.candidate" && test ! -L "\$ssh_recovery/authorized_keys.candidate"
test ! -e "\$ssh_recovery/authorized_keys.directory.before" && test ! -L "\$ssh_recovery/authorized_keys.directory.before"
if test -e "\$ssh_root/authorized_keys" || test -L "\$ssh_root/authorized_keys"; then
  test ! -L "\$ssh_root/authorized_keys" && test -d "\$ssh_root/authorized_keys"
  printf '%s\n' "\$(stat -c '%u:%g:%a' "\$ssh_root/authorized_keys")" > "\$ssh_recovery/authorized_keys.directory.before"
else
  printf '%s\n' absent > "\$ssh_recovery/authorized_keys.directory.before"
fi

candidate_tmp=\$(mktemp "\$ssh_recovery/authorized_keys.candidate.XXXXXX")
printf %s '$mergedB64' | base64 -d > "\$candidate_tmp"
chmod 0600 "\$candidate_tmp"
mv -f "\$candidate_tmp" "\$ssh_recovery/authorized_keys.candidate"
test -f "\$ssh_recovery/authorized_keys.candidate" && test ! -L "\$ssh_recovery/authorized_keys.candidate"

install -d -o root -g root -m 0755 "\$ssh_root/authorized_keys"
test ! -L "\$ssh_root/authorized_keys" && test -d "\$ssh_root/authorized_keys"
chown root:root "\$ssh_root/authorized_keys"
chmod 0755 "\$ssh_root/authorized_keys"
test "\$(stat -c '%u:%g:%a' "\$ssh_root/authorized_keys")" = '0:0:755'
ucmp_check_old_authorized_keys

test ! -e "\$ssh_root/.ucmp-authorized-keys-new" && test ! -L "\$ssh_root/.ucmp-authorized-keys-new"
install -o root -g root -m 0644 "\$ssh_recovery/authorized_keys.candidate" "\$ssh_root/.ucmp-authorized-keys-new"
test ! -L "\$ssh_root/.ucmp-authorized-keys-new" && test -f "\$ssh_root/.ucmp-authorized-keys-new"
test "\$(stat -c '%u:%g:%a' "\$ssh_root/.ucmp-authorized-keys-new")" = '0:0:644'
ucmp_check_old_authorized_keys
test "\$(stat -c '%u:%g:%a' "\$ssh_root/authorized_keys")" = '0:0:755'
test ! -L "\$ssh_root/authorized_keys/ucmp-tftp"
mv -f "\$ssh_root/.ucmp-authorized-keys-new" "\$ssh_root/authorized_keys/ucmp-tftp"
test -f "\$ssh_root/authorized_keys/ucmp-tftp" && test ! -L "\$ssh_root/authorized_keys/ucmp-tftp"
test ! -e "\$ssh_recovery/authorized_keys.committed" && test ! -L "\$ssh_recovery/authorized_keys.committed"
touch "\$ssh_recovery/authorized_keys.committed"
test "\$(stat -c '%u:%g:%a' "\$ssh_root/authorized_keys/ucmp-tftp")" = '0:0:644'
test "\$(sha256sum "\$ssh_root/authorized_keys/ucmp-tftp" | cut -d' ' -f1)" = '$mergedHash' || { echo 'Published authorized_keys bytes do not match the merged preflight candidate' >&2; exit 73; }
runuser -u ucmp-tftp -- test -r "\$ssh_root/authorized_keys/ucmp-tftp" || { echo 'ucmp-tftp cannot read authorized_keys through its parent directories' >&2; exit 74; }

SH;
    }

    public static function scripts(string $id,string $block,string $configRoot='/etc/ssh'): array
    {
        if (!preg_match('/^[a-f0-9]{24}$/D',$id)) { throw new \InvalidArgumentException('Invalid SSH enrollment transaction'); }
        $root=rtrim($configRoot,'/'); $recovery=$root . '/ucmp-recovery-' . $id;
        $reload='systemctl reload sshd >/dev/null 2>&1 || systemctl reload ssh >/dev/null 2>&1 || service sshd reload >/dev/null 2>&1 || service ssh reload >/dev/null 2>&1';
        $vars='ssh_root=' . escapeshellarg($root) . "\nssh_recovery=" . escapeshellarg($recovery) . "\n";
        $restore=<<<'SH'
set -eu
test -f "$ssh_recovery/sshd_config.before" && test ! -L "$ssh_recovery/sshd_config.before"
cmp -s "$ssh_root/sshd_config" "$ssh_recovery/sshd_config.before" || cmp -s "$ssh_root/sshd_config" "$ssh_recovery/sshd_config.candidate" || { echo 'SSH policy changed after enrollment; retained backup requires review' >&2; exit 73; }
test ! -L "$ssh_root/sshd_config" && test -f "$ssh_root/sshd_config"
if test -e "$ssh_recovery/authorized_keys.candidate" || test -L "$ssh_recovery/authorized_keys.candidate"; then
  test ! -L "$ssh_recovery/authorized_keys.candidate" && test -f "$ssh_recovery/authorized_keys.candidate"
  if test -e "$ssh_recovery/authorized_keys.committed" || test -L "$ssh_recovery/authorized_keys.committed"; then
    test ! -L "$ssh_recovery/authorized_keys.committed" && test -f "$ssh_recovery/authorized_keys.committed"
    ucmp_key_committed=1
  else
    ucmp_key_committed=0
  fi
  test ! -L "$ssh_root/authorized_keys" && test -d "$ssh_root/authorized_keys"
  test ! -L "$ssh_root/authorized_keys/ucmp-tftp"
  if test -e "$ssh_root/authorized_keys/ucmp-tftp"; then
    test -f "$ssh_root/authorized_keys/ucmp-tftp"
    if cmp -s "$ssh_root/authorized_keys/ucmp-tftp" "$ssh_recovery/authorized_keys.candidate"; then
      ucmp_restore_authorized_keys=1
    elif test "$ucmp_key_committed" = 0 && test -f "$ssh_recovery/authorized_keys.before" && cmp -s "$ssh_root/authorized_keys/ucmp-tftp" "$ssh_recovery/authorized_keys.before"; then
      ucmp_restore_authorized_keys=0
    else
      echo 'SSH authorized_keys changed after enrollment; retained backup requires review' >&2
      exit 73
    fi
  elif test "$ucmp_key_committed" = 0 && test -f "$ssh_recovery/authorized_keys.absent"; then
    ucmp_restore_authorized_keys=0
  else
    echo 'SSH authorized_keys disappeared after enrollment; retained backup requires review' >&2
    exit 73
  fi
else
  ucmp_restore_authorized_keys=0
fi
if test -e "$ssh_recovery/authorized_keys.directory.before" || test -L "$ssh_recovery/authorized_keys.directory.before"; then
  test ! -L "$ssh_recovery/authorized_keys.directory.before" && test -f "$ssh_recovery/authorized_keys.directory.before"
  ucmp_directory_before=$(cat "$ssh_recovery/authorized_keys.directory.before")
  if test "$ucmp_directory_before" != absent; then
    case "$ucmp_directory_before" in
      ''|*[!0-9:]*) echo 'Invalid retained authorized_keys directory metadata' >&2; exit 73;;
    esac
    IFS=: read -r ucmp_uid ucmp_gid ucmp_mode < "$ssh_recovery/authorized_keys.directory.before"
    test -n "$ucmp_uid" && test -n "$ucmp_gid" && test -n "$ucmp_mode" || { echo 'Invalid retained authorized_keys directory metadata' >&2; exit 73; }
  fi
  if test "$ucmp_directory_before" = absent; then
    if test -e "$ssh_root/authorized_keys" || test -L "$ssh_root/authorized_keys"; then
      test ! -L "$ssh_root/authorized_keys" && test -d "$ssh_root/authorized_keys"
      test "$(stat -c '%u:%g:%a' "$ssh_root/authorized_keys")" = '0:0:755'
    fi
  else
    test -d "$ssh_root/authorized_keys" && test ! -L "$ssh_root/authorized_keys"
    ucmp_directory_current=$(stat -c '%u:%g:%a' "$ssh_root/authorized_keys")
    if test "$ucmp_directory_current" != "$ucmp_directory_before" && test "$ucmp_directory_current" != '0:0:755'; then
      echo 'SSH authorized_keys directory changed after enrollment; retained backup requires review' >&2
      exit 73
    fi
  fi
fi
sshd -t -f "$ssh_recovery/sshd_config.before"
test ! -e "$ssh_root/.ucmp-restore" && test ! -L "$ssh_root/.ucmp-restore"
cp -p "$ssh_recovery/sshd_config.before" "$ssh_root/.ucmp-restore"
mv -f "$ssh_root/.ucmp-restore" "$ssh_root/sshd_config"
if test "$ucmp_restore_authorized_keys" = 1 && test -f "$ssh_recovery/authorized_keys.before"; then
  test ! -e "$ssh_root/authorized_keys/.ucmp-tftp-restore" && test ! -L "$ssh_root/authorized_keys/.ucmp-tftp-restore"
  cp -p "$ssh_recovery/authorized_keys.before" "$ssh_root/authorized_keys/.ucmp-tftp-restore"
  test ! -L "$ssh_root/authorized_keys/ucmp-tftp"
  mv -f "$ssh_root/authorized_keys/.ucmp-tftp-restore" "$ssh_root/authorized_keys/ucmp-tftp"
elif test "$ucmp_restore_authorized_keys" = 1 && test -f "$ssh_recovery/authorized_keys.absent"; then
  test ! -L "$ssh_root/authorized_keys/ucmp-tftp"
  rm -f "$ssh_root/authorized_keys/ucmp-tftp"
elif test "$ucmp_restore_authorized_keys" = 1; then
  echo 'SSH authorized_keys rollback backup is missing' >&2
  exit 73
fi
if test -f "$ssh_recovery/authorized_keys.directory.before"; then
  if test "$ucmp_directory_before" = absent; then
    if test -e "$ssh_root/authorized_keys" || test -L "$ssh_root/authorized_keys"; then
      test ! -L "$ssh_root/authorized_keys" && test -d "$ssh_root/authorized_keys"
      rmdir "$ssh_root/authorized_keys"
    fi
  else
    if test -d "$ssh_root/authorized_keys" && test ! -L "$ssh_root/authorized_keys"; then
      IFS=: read -r ucmp_uid ucmp_gid ucmp_mode < "$ssh_recovery/authorized_keys.directory.before"
      case "$ucmp_uid:$ucmp_gid:$ucmp_mode" in
        ''|*[!0-9:]*) echo 'Invalid retained authorized_keys directory metadata' >&2; exit 73;;
      esac
      if test "$ucmp_directory_current" = '0:0:755'; then
        chown "$ucmp_uid:$ucmp_gid" "$ssh_root/authorized_keys"
        chmod "$ucmp_mode" "$ssh_root/authorized_keys"
        test "$(stat -c '%u:%g:%a' "$ssh_root/authorized_keys")" = "$ucmp_directory_before"
      fi
    fi
  fi
fi
SH;
        $restore.="\n" . $reload . "\n";
        $setup=$vars . <<<'SH'
test -d "$ssh_root" && test ! -L "$ssh_root"
test -f "$ssh_root/sshd_config" && test ! -L "$ssh_root/sshd_config"
test ! -L "$ssh_root/authorized_keys"
if test -e "$ssh_root/authorized_keys"; then test -d "$ssh_root/authorized_keys"; fi
test ! -L "$ssh_root/authorized_keys/ucmp-tftp"
if test -e "$ssh_root/authorized_keys/ucmp-tftp"; then test -f "$ssh_root/authorized_keys/ucmp-tftp"; fi
test ! -L "$ssh_root/.ucmp-enrollment.lock"
if test -e "$ssh_root/.ucmp-enrollment.lock"; then test -f "$ssh_root/.ucmp-enrollment.lock"; fi
exec 8>"$ssh_root/.ucmp-enrollment.lock"
flock -n 8 || { echo 'Another SSH enrollment is active' >&2; exit 75; }
umask 077
mkdir -m 0700 "$ssh_recovery"
cp -p "$ssh_root/sshd_config" "$ssh_recovery/sshd_config.before"
if test -f "$ssh_root/authorized_keys/ucmp-tftp"; then
  cp -p "$ssh_root/authorized_keys/ucmp-tftp" "$ssh_recovery/authorized_keys.before"
else
  touch "$ssh_recovery/authorized_keys.absent"
fi
SH;
        $setup.="\nrollback_ucmp_ssh() {\nexport ssh_root ssh_recovery\nsh -eu <<'UCMP_RESTORE'\n" . $restore . "UCMP_RESTORE\n}\n"
            . "trap 'rc=\$?; trap - EXIT HUP INT TERM; rollback_ucmp_ssh || echo SSH_recovery_required >&2; exit \"\$rc\"' EXIT\n"
            . "trap 'exit 130' HUP INT TERM\n";
        $stage=<<<'SH'
test ! -L "$ssh_root/sshd_config" && test -f "$ssh_root/sshd_config"
cmp -s "$ssh_root/sshd_config" "$ssh_recovery/sshd_config.before" || { echo 'SSH policy changed during enrollment; refusing to replace concurrent policy' >&2; exit 73; }
cp -p "$ssh_recovery/sshd_config.before" "$ssh_recovery/sshd_config.candidate"
sed -i '/# BEGIN UCM PROVISIONER PBX TFTP/,/# END UCM PROVISIONER PBX TFTP/d' "$ssh_recovery/sshd_config.candidate"
sed -i '/# ucmprovisioner: least-privilege SFTP-only access/,/^[[:space:]]*PermitTunnel no[[:space:]]*$/d' "$ssh_recovery/sshd_config.candidate"
SH;
        $stage.="\nprintf %s " . escapeshellarg(base64_encode("\nMatch all\n" . trim($block) . "\nMatch all\n")) . " | base64 -d >> \"\$ssh_recovery/sshd_config.candidate\"\n"
            . "sshd -t -f \"\$ssh_recovery/sshd_config.candidate\"\n"
            . "test ! -e \"\$ssh_root/.ucmp-sshd-new\" && test ! -L \"\$ssh_root/.ucmp-sshd-new\"\n"
            . "cp -p \"\$ssh_recovery/sshd_config.candidate\" \"\$ssh_root/.ucmp-sshd-new\"\n"
            . "test ! -L \"\$ssh_root/sshd_config\" && test -f \"\$ssh_root/sshd_config\"\n"
            . "cmp -s \"\$ssh_root/sshd_config\" \"\$ssh_recovery/sshd_config.before\" || { echo 'SSH policy changed during enrollment; refusing to activate concurrent policy' >&2; exit 73; }\n"
            . "mv -f \"\$ssh_root/.ucmp-sshd-new\" \"\$ssh_root/sshd_config\"\n" . $reload . "\n";
        return ['setup'=>$setup,'stage'=>$stage,'finish'=>"trap - EXIT HUP INT TERM\necho " . escapeshellarg('SSH rollback retained: ' . $id) . "\n",
            'rollback'=>"set -eu\n" . $vars . "exec 8>\"\$ssh_root/.ucmp-enrollment.lock\"\nflock -n 8\n" . $restore,
            'restore'=>$restore,
            'recovery_id'=>$id];
    }
}
