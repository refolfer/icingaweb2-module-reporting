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

There are four permissions that can be used to control what can be accessed and managed by whom.

| Permission           | Applies to                              |
|----------------------|-----------------------------------------|
| reporting/reports    | Reports (view, create, edit, delete)    |
| reporting/schedules  | Schedules (create, edit, delete)        |
| reporting/templates  | Templates (view, create, edit, delete)  |
| reporting/timeframes | Timeframes (view, create, edit, delete) |

## Restrictions

The module provides the following restrictions:

| Restriction                | Applies to                                                 |
|----------------------------|------------------------------------------------------------|
| reporting/users            | Reporting access for the listed users                      |
| reporting/groups           | Reporting access for members of the listed groups          |
| reporting/filter/objects   | Monitored objects that match the filter                    |

`reporting/users` and `reporting/groups` accept comma-separated or line-separated
values. If neither restriction is configured, access to reporting is not limited
by user or group. Use `*` in either restriction to allow every authenticated user.
When either restriction is configured for the current role, report lists and
direct report URLs are additionally limited to reports authored by the current
user unless the user is unrestricted.

`reporting/filter/objects` uses the same filter expression syntax as Icinga DB
and is applied during interactive report rendering and export to reportlets that
expose a `filter` setting, such as host and service SLA reports.

For SLA reports, the report form also provides an `SLA Visualization` option.
It controls the HTML and PDF presentation of the report and can show SLA results
as the default table, horizontal bars, columns, availability balance columns or
pie charts. CSV and JSON exports continue to use tabular data.

The module also provides an `Outage Report (Icinga DB)` report. It uses the
Icinga DB database resource configured in the `icingadb` module, or the
`Icinga DB Resource Config` setting in the reporting module's backend
configuration if you want to override it. This setting is stored as
`[icingadb] resource` in the reporting module configuration. The report lists
host and service outage intervals, includes
check output details and renders time series charts for each affected object.
Its object filter supports simple text searches as well as exact Icinga DB
object filters for `host.name`, `service.name`, `hostgroup.name` and
`servicegroup.name`.

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
