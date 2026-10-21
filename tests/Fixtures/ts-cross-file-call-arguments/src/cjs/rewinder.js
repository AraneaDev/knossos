// CommonJS: a function expression exported whole, which has no node of its
// own, so its calls are the module's.
module.exports = function ({ tape }) {
    tape.stop();
};
