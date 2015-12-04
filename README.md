# arduino-builder-service

Arduino Builder Service modifies codebender/compiler to compile skits with arduino-builder. As of now Arduino Builder Service compiles for version 166 for Arduino 1.6.6.

## Setup Arduino Builder Service with Vagrant
Install [Vagrant](https://www.vagrantup.com/downloads.html) on your machine.

Next, copy the code into any directory. `git clone https://github.com/codebendercc/arduino-builder-service.git`
Go into the directory with the code and `vagrant up`
SSH into the virtual machine using
```
host: localhost
port: 2222
username: vagrant
password: vagrant

```
Finally, set up the virtual machine by running `/vagrant/setup.sh`
To test if it work run `curl https://localhost/status` should return {"success":true,"status":"OK"}.
