const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
    main: path.join(__dirname, 'src', 'main.js'),
    adminSettings: path.join(__dirname, 'src', 'adminSettings.js'),
    fileAction: path.join(__dirname, 'src', 'fileAction.js'),
}

module.exports = webpackConfig
