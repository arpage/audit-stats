# Get logs from our NewRelic Dashboard

A person will need to navigate to our NewRelic Dashboard, and pull one or more csv files from several of the Dashboard tabs

## Log file acquisition

### Prep
Create a folder named for the end-date of the week for which we are collecting data:

e.g. `YYYY-MM-DD`

The log files shoulds be placed in here.

The analysis scripts assume a 7-day period in the same week for each log file, so you will need to set the date range filter on the NewRelic Dashboard accordingly.

The convention is _begin-date_ 00:00UTC to _end-date_ 01:00UTC

Now we can begin to download our logs.  Make SURE the date range filter is set before downloading from these tabs

### Modsecurity Tab

https://one.newrelic.com/dashboards/detail/MzQ4OTE2NHxWSVp8REFTSEJPQVJEfGRhOjI5NTA1MDE?state=f3e21a2a-41a2-0367-762c-0e29fa0af5dd

#### Use the `...` menu on the following sections and choose Export as CSV

1. ModSecurity Log Messages section
1. ModSecurity Logs section

### Security Tab

https://one.newrelic.com/dashboards/detail/MzQ4OTE2NHxWSVp8REFTSEJPQVJEfGRhOjI5NTA1MDE?state=08ea9f27-8a45-6fca-2e32-80399cc39b2d

#### Use the `...` menu on the following sections and choose Export as CSV

1. Proxy DENIED Section
1. Proxy allowed Section
1. SSH Activity
1. CF API messages

### Infra Events Tab

https://one.newrelic.com/dashboards/detail/MzQ4OTE2NHxWSVp8REFTSEJPQVJEfGRhOjI5NTA1MDE?state=13397506-4636-2509-fcb3-943a01c79882

#### Use the `...` menu on the following sections and choose Export as CSV

1. CFEvents Logs

## Log file naming

### It is important to keep the names of the log files as-is, with the following caveat:

Prepend each log file with the ending date of the week for which the logs were gathered:

e.g. `2026-03-39-CF-API-Events.log`

So the format is: `YYYY-MM-DD-LOGFILE.log`

## Final destination

After they are renamed, drop each CSV file into the date folder described in the **Prep** section above

This date folder should be moved/copied to `projects/audit-stats/log-files`

The data is now ready to be analyzed by our tools
