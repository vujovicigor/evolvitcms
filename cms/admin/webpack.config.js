const path = require('path');

module.exports = {
  entry: './app.js',
  target: 'web',
  output: {
    path: path.resolve(__dirname, './js'),
    filename: 'bundle.js'
  },
  module: {
    rules: [
      { test: /\.html$/, use: 'ractive-component-loader' }
    ]
  },
  resolve: {
    // node core modules are not used in the browser bundle
    fallback: { fs: false, net: false, tls: false }
  },
  performance: { hints: false }
};
