# meshtastic-web-info

Deals with output from "meshtastic --info" and sends it to webserver with PHP script, which saves node info into MySQL DB and shows web page with all nodes so far discovered.

Example: https://mesh.tricker.cz/

## Installation

### Server with Meshtastic device

Meshtastic must be connected to any device which can run [Meshtastic Python CLI](https://meshtastic.org/docs/software/python/cli/). 
On this server with connected meshtastic device, create script for example in path `/home/pi/meshinfo`. 

In script, change two paths:
* `/home/pi/meshtastic-info` to file where to save output from `meshtastic --info` command
* `https://your-url.com/index.php` to your server URL where to send data:

```bash
#!/bin/bash

/home/pi/.local/bin/meshtastic --info > /home/pi/meshtastic-info
curl -s -F "file=@/home/pi/meshtastic-info" https://your-url.com/index.php >> /dev/null
``` 

Now, if you run this script `/home/pi/meshinfo` (maybe do `chmod +x /home/pi/meshinfo` first), it should 
save output into first path. If so, you can add this script to crontab to run every 15 minutes like:

```bash
*/15 * * * * /home/pi/meshinfo >> /dev/null
```
### Webserver

Create database access and table with following structure:

```sql
CREATE TABLE `Nodes` (
  `NodeId` varchar(20) NOT NULL,
  `NodeData` text NOT NULL,
  PRIMARY KEY (`NodeId`),
  UNIQUE KEY `NodeIdUnique` (`NodeId`),
  KEY `NodeIdIndex` (`NodeId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

Put `index.php` file from this repository to your webserver. On top of this file, change configuration - 
name of page, allowed IP address to have incoming Meshtastic data and database access.

Script creates and changes files `nodes.html` and `raw-info-data.txt` in same directory where `index.php` is.
Make sure that webserver has write access to this directory or at least this two files.

If all setu up correctly, you should see web page with all nodes discovered so far 🥳

