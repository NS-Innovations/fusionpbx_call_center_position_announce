# Call Center Position for FusionPBX

Tell callers waiting in a FusionPBX call center queue their position in line.

Every so many seconds a waiting caller hears *"You are number three in line."* over the music on
hold, and the music carries on afterwards. Nothing in FusionPBX core or the FreeSWITCH configuration
is changed.

---

## Turning it on for a queue

Edit the queue under **Applications -> Call Center** and set **Announce Position** to **True**.

The Call Center app already has that field, but it does nothing on its own, and its permission is
given to no group, so the field is hidden. This app gives `call_center_announce_position` to the
`admin` and `superadmin` groups, which shows it, and supplies the service that makes it work.

A change is picked up within 15 seconds. Saving the queue again or reloading anything is not needed.

## Settings

Under **Advanced -> Default Settings**, category `call_center_position`. Every setting except
`enabled` can be overridden per domain under **Domain Settings**.

| Subcategory | Default | Meaning |
|---|---|---|
| `enabled` | `true` | Turns the service off without stopping it. |
| `interval` | `30` | Seconds between announcements to a caller. The first one comes one interval after the caller joins the queue. Minimum `10`. |
| `sound_before` | `ivr/ivr-you_are_number.wav` | Played before the number. Empty leaves it out. |
| `sound_after` | `ivr/ivr-in_line.wav` | Played after the number. Empty leaves it out. |

A relative sound path resolves against the language and voice of the queue, the same way the rest
of the queue's prompts do. An absolute path, for example a recording, works too, but it cannot
contain spaces or `!`.

---

## How it works

A small service, `call_center_position`, runs once a second:

1. Every 15 seconds it reads the queues with **Announce Position** set to `true` from the database,
   with the settings of their domain.
2. For each of those queues it asks mod_callcenter for the members with
   `json {"command":"callcenter_config","data":{"arguments":"queue list members",...}}`. They come
   back in the order mod_callcenter serves them, highest score first, where the score is the time
   waited plus the base and skill scores.
3. A caller's position is one more than the number of callers ahead of them who are `Waiting` or
   being offered to an agent (`Trying`).
4. When a `Waiting` caller is due, it sends
   `uuid_broadcast <uuid> file_string://ivr/ivr-you_are_number.wav!digits/3.wav!ivr/ivr-in_line.wav aleg`.
   mod_callcenter allows broadcasts to a member and stops them itself when the caller leaves the
   queue.

A caller whose agent is ringing is not spoken over. A caller whose agent does not answer keeps
their timer and is not announced again straight away.

---

## Installing

Requires FusionPBX 5.5 or later (it uses the `service` class and the `settings` object) and
FreeSWITCH with mod_callcenter.

```sh
cd /var/www/fusionpbx/app
git clone https://github.com/NS-Innovations/fusionpbx_call_center_position_announce.git call_center_position
chown -R www-data:www-data call_center_position
cd /var/www/fusionpbx/core/upgrade
php upgrade.php
php upgrade.php --group
php upgrade.php --service update
```

**Clone into `call_center_position`, not the repository's own name.** FusionPBX finds the app, its
class and its service by that directory name.

- `upgrade.php` adds the default settings.
- `--group` gives `admin` and `superadmin` the permission that shows the **Announce Position** field.
  Note that it also resets every unprotected group's permissions to the defaults, as it always does.
- `--service update` installs, enables and starts `/etc/systemd/system/call_center_position.service`.

Check it is running with `systemctl status call_center_position`.

On FreeBSD the app lives under `/usr/local/www/fusionpbx`; enable the rc.d script with
`call_center_position_enable="YES"` in `/etc/rc.conf`.

## Updating

```sh
cd /var/www/fusionpbx/app/call_center_position
git pull
systemctl restart call_center_position
```

## Removing

```sh
systemctl disable --now call_center_position
rm /etc/systemd/system/call_center_position.service
systemctl daemon-reload
rm -r /var/www/fusionpbx/app/call_center_position
```

Then delete the `call_center_position` rows from Default Settings and Domain Settings, and the
`call_center_position` row from `v_services`. The `call_center_announce_position` permission belongs
to the Call Center app, so it stays.

---

## Limits

- **Numbers are put together the English way.** 1 to 20 is a single file and works in every
  language. Above 20 it plays tens then units (`digits/30.wav` + `digits/4.wav`), and hundreds as
  `digits/2.wav` + `digits/hundred.wav`, which is wrong for a language that says numbers differently.
  Positions above 999 are not announced.
- **The position is the serving order of mod_callcenter.** With base or skill scores in use, a caller
  can be told a smaller number than the one they had, because someone with a higher score was put
  ahead of them.
- **One switch.** The service talks to the switch configured for FusionPBX's event socket.

---

## License

MIT, see [LICENSE](LICENSE). Copyright (c) 2026 Norman King.

The app runs inside FusionPBX, which is licensed separately under the Mozilla Public License 1.1;
this repository contains none of FusionPBX's own files.
