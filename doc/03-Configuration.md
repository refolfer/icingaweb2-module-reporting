# Configuration

Icinga Reporting is configured via the web interface. Below you will find an overview of the necessary settings.

## Backend

Icinga Reporting stores all its configuration in the database, therefore you need to create and configure a database
resource for it.

1. Create a new resource for Icinga Reporting via the `Configuration -> Application -> Resources` menu.

2. Configure the resource you just created as the database connection for Icinga Reporting using the
   `Configuration → Modules → reporting → Backend` menu. If you've used `reporting`
   as name for the resource, this is optional.

## Mail

At `Configuration -> Modules -> reporting -> Mail` you can configure the address
that is used as the sender's address (From) in E-mails.

## Permissions

There are four permissions that can be used to control what can be managed by whom.

| Permission           | Applies to                        |
|----------------------|-----------------------------------|
| reporting/reports    | Reports (create, edit, delete)    |
| reporting/schedules  | Schedules (create, edit, delete)  |
| reporting/templates  | Templates (create, edit, delete)  |
| reporting/timeframes | Timeframes (create, edit, delete) |

## Restrictions

The module provides the `reporting/filter/objects` restriction. It uses the same
filter expression syntax as Icinga DB and is applied during interactive report
rendering and export to reportlets that expose a `filter` setting, such as host
and service SLA reports.

This can be used to limit report data to a subset of monitored objects, for
example:

```
hostgroup.name=linux-servers
```

## Icinga Reporting Daemon

There is a daemon for generating and distributing reports on a schedule if configured:

```
icingacli reporting schedule run
```

This command schedules the execution of all applicable reports.

The `systemd` service of this module uses this command as well.

To configure this as a `systemd` service, copy the example service definition from
`/usr/share/icingaweb2/modules/reporting/config/systemd/icinga-reporting.service`
to `/etc/systemd/system/icinga-reporting.service`.

You can run the following command to enable and start the daemon.

```
systemctl enable --now icinga-reporting.service
```
