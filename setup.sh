cd /home/vagrant
sudo apt-get install git
git clone https://github.com/codebendercc/compiler.git
cp -r /vagrant/modified-files/CompilerBundle/ compiler/Symfony/src/Codebender/
cd /home/vagrant/compiler/
./scripts/install.sh
cd /home/vagrant
wget http://arduino.cc/download.php?f=/arduino-1.6.6-linux64.tar.xz -O arduino-1.6.6.tar.xz
tar -xJf arduino-1.6.6.tar.xz
sudo mkdir /opt/codebender/arduino-core-files
sudo mkdir /opt/codebender/arduino-core-files/v1.6.6
sudo cp -r arduino-1.6.6/hardware /opt/codebender/arduino-core-files/v1.6.6/
sudo cp -r arduino-1.6.6/libraries /opt/codebender/arduino-core-files/v1.6.6/
sudo mkdir /opt/arduino-builder
sudo cp -r arduino-1.6.6/tools-builder /opt/arduino-builder/
sudo cp -r arduino-1.6.6/arduino-builder /opt/arduino-builder/