const wind = require('./winder.js');
const rewind = require('./rewinder.js');
const { eject } = require('./ejector.js');
const lib = require('./lib.js');
const { camera } = require('../devices.js');

function play() {
    wind({ tape: camera() });
    rewind({ tape: camera() });
    eject({ tape: camera() });
    lib({ tape: camera() });
}

module.exports = { play };
