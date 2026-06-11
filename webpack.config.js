const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
    main: path.join(__dirname, 'src', 'main.js'),
    adminSettings: path.join(__dirname, 'src', 'adminSettings.js'),
    fileAction: path.join(__dirname, 'src', 'fileAction.js'),
    'fileAction-nc32': path.join(__dirname, 'src', 'fileAction-nc32.js'),
}

// @nextcloud/webpack-vue-config hardcodes publicPath to /apps/{appId}/js/ but this
// app lives in custom_apps/. Override to 'auto' so webpack determines the public path
// at runtime from the actual script URL, making lazy-loaded chunks work correctly.
webpackConfig.output.publicPath = 'auto'

// ESM modules (e.g. axios ≥1.17, vue-router devtools) import node polyfills
// (buffer, process) without file extensions. Webpack 5 strict ESM resolution
// requires fully-specified paths inside .mjs/.js-ESM contexts, so we disable
// that requirement globally for JS/MJS files to keep the build working.
webpackConfig.module.rules.push({
    test: /\.m?js$/,
    resolve: {
        fullySpecified: false,
    },
})

module.exports = webpackConfig
