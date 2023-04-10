<?php

namespace Dubya\Plugin\Updater;

trait GitHubTrait
{
    // dpu = Dubya Plugin Updater
    protected $dpuMeta;
    protected $dpuPluginFile;
    protected $dpuPluginSlug;
    protected $dpuPluginDir;
    protected $dpuReleaseChannels = [
        'stable',
    ];
    protected $dpuStableAliases = [
        'master',
        'main',
    ];

    /**
     * @param string $pluginFile
     */
    public function pluginUpdaterGitHub(string $pluginFile)
    {
        $this->dpuMeta = get_file_data($pluginFile, [
            'Version' => 'Version',
            'RepoVisibility' => 'Repo Visibility',
            'ReleaseChannels' => 'Release Channels',
            'UpdateURI' => 'Update URI',
        ], 'plugin');

        $this->dpuPluginFile = $pluginFile;
        $this->dpuPluginSlug = plugin_basename($pluginFile);
        $this->dpuPluginDir = plugin_dir_path($pluginFile);

        if (!empty($this->dpuMeta['UpdateURI'])) {
            $path = parse_url($this->dpuMeta['UpdateURI'], PHP_URL_PATH);
            $this->dpuMeta['GitHubRepo'] = trim($path, '/');
        }

        if (!empty($this->dpuMeta['ReleaseChannels'])) {
            $this->dpuReleaseChannels = array_map('trim', explode(',', $this->dpuMeta['ReleaseChannels']));
        }

        /**
         * Data urls are not evil. This allows us to use embedded icons and banners in the plugin
         * update-info.json response. Without this, esc_url() will strip out the data: protocol.
         *
         * @since 1.0.0
         */
        add_filter('kses_allowed_protocols', function ($protocols) {
            $protocols[] = 'data';
            return $protocols;
        });

        add_filter('update_plugins_github.com', [$this, 'dpuOnUpdateGitHubPlugins'], 10, 4);
        add_filter('plugins_api', [$this, 'dpuOnPluginsApi'], 10, 3);
    }

    /**
     * See the UpdateURI section of wp_update_plugins() in wp-admin/includes/update.php
     *
     * @see https://developer.wordpress.org/reference/functions/wp_update_plugins/
     * @see https://developer.wordpress.org/reference/hooks/update_plugins_hostname/
     *
     * @param mixed $update
     * @param string $pluginData
     * @param array $pluginFile
     * @param mixed $locales
     * @return mixed
     */
    public function dpuOnUpdateGitHubPlugins($update, $pluginData, $pluginFile, $locales)
    {
        // debugging
        do_action('qm/debug', 'dpuOnUpdateGitHubPlugins update: should be mixed, is: ' . var_export($update, true));
        do_action('qm/debug', 'dpuOnUpdateGitHubPlugins pluginFile: should be string, is: ' . $pluginFile);
        do_action('qm/debug', 'dpuOnUpdateGitHubPlugins pluginData: should be array, is: ' . print_r($pluginData, true));

        $incoming = plugin_basename($pluginFile);

        // if this is not our plugin, bail
        if ($this->dpuPluginSlug !== $incoming) {
            do_action('qm/debug', "not our plugin: {$this->dpuPluginSlug} !== {$incoming}");
            return $update;
        }

        $update = $this->dpuGetLatestRelease();

        return $update;
    }

    public function dpuOnPluginsApi($result, $action, $args)
    {
        // debugging
        do_action('qm/debug', 'dpuOnPluginsApi result: should be mixed, is: ' . var_export($result, true));
        do_action('qm/debug', 'dpuOnPluginsApi action: should be string, is: ' . $action);
        do_action('qm/debug', 'dpuOnPluginsApi args: should be array, is: ' . print_r($args, true));

        // we only want to handle the plugin information request
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (dirname($this->dpuPluginSlug) !== $args->slug) {
            return $result;
        }

        $latest = $this->dpuGetLatestRelease();

        if ($latest === false) {
            return $result;
        }

        // $result = new stdClass();
        // $result->name = $update->name;
        // $result->slug = $this->pluginSlug;
        // $result->version = $update->version;
        // $result->author = $update->author;
        // $result->author_profile = $update->author_profile;
        // $result->requires = $update->requires;
        // $result->tested = $update->tested;
        // $result->requires_php = $update->requires_php;
        // $result->last_updated = $update->last_updated;
        // $result->homepage = $update->homepage;
        // $result->download_link = $update->download_link;
        // $result->banners = $update->banners;
        // $result->icons = $update->icons;
        // $result->sections = $update->sections;
        // $result->short_description = $update->short_description;
        // $result->tags = $update->tags;
        // $result->contributors = $update->contributors;
        // $result->donate_link = $update->donate_link;
        // $result->rating = $update->rating;
        // $result->num_ratings = $update->num_ratings;
        // $result->active_installs = $update->active_installs;
        // $result->added = $update->added;
        // $result->compatibility = $update->compatibility;
        // $result->upgrade_notice = $update->upgrade_notice;

        // this stuff is all part of what we put in the update-info.json anyway.
        // return that for now and see what we get.
        $result = $latest;
        return $result;
    }

    /**
     * Get the latest release for this plugin.
     *
     * @since 1.0.0
     *
     * @return array|null
     */
    private function dpuGetLatestRelease()
    {
        $releases = $this->dpuGetLatestViableReleases();
        if ($releases == false) {
            return $releases;
        }

        // TODO determine selection for viable update channels.
        // for now, we return the first one that's not null
        foreach ($releases as $channel => $info) {
            if ($info !== null) {
                $update = $info;
                break;
            }
        }

        if (!isset($update)) {
            return false;
        }

        /**
         * Filter the content of the update info response.
         * This is a good opportunity to (for example) apply
         * markdown-to-html conversion to the release notes.
         *
         * @since 1.0.0
         */
        $update = apply_filters('dubya/format_release_notes', $update);

        return $update;
    }

    /**
     * If you need to add a GitHub access token to your requests, you can do so here, using the
     * update_plugins_github.com_headers filter or the update_plugins_github.com_{pluginSlug}_headers
     * filter (which are called in that order).
     *
     * @return array
     */
    private function dpuPrepRequestHeaders()
    {
        // is this a private repo?
        $visibility = $this->dpuMeta['RepoVisibility'] ?? 'public';

        $args = [
            'httpversion' => '1.1',
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json,application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'X-GitHub-Api-Version' => '2022-11-28'
            ],
        ];

        $args = apply_filters('update_plugins_github.com_headers', $args, $visibility);
        $args = apply_filters('update_plugins_github.com_' . $this->dpuPluginSlug . '_headers', $args, $visibility);

        return $args;
    }

    /**
     * Fetch releases data from the GitHub API.
     *
     * We will consider any release matching the current release channels and containing an
     * update-info.json file as one of the release artifacts.
     *
     * The update-info.json file should contain metadata about the release, including the version number.
     *
     * @since 1.0.0
     */
    private function dpuGetRemoteReleases()
    {
        $args = $this->dpuPrepRequestHeaders();

        /**
         * The `update_plugins_github.com_{pluginSlug}_host` filter.
         *
         * If you need to change the hostname to something other than api.github.com,
         * you can do so here. This might be useful if you are using GitHub Enterprise.
         *
         * @since 1.0.0
         */
        $apiHost = apply_filters('update_plugins_github.com_' . $this->dpuPluginSlug . '_apihost', 'api.github.com');
        $url = 'https://' . $apiHost . '/repos/' . $this->dpuMeta['GitHubRepo'] . '/releases';

        /**
         * The `update_plugins_github.com_{pluginSlug}_release_channels` filter.
         *
         * This filter allows setting the release channel to something other
         * than (or in addition to) 'stable'.
         *
         * If the only release channel is 'stable' (the default), then we will use the GitHub API's
         * 'latest release' endpoint, which is more efficient than fetching all releases.
         *
         * If this filter results in multiple release channels, we will fetch all releases and
         * filter them by the value of release 'target_commitish' (which is the branch name
         * the release was merged into).
         *
         * @since 1.0.0
         *
         * @see https://semantic-release.gitbook.io/semantic-release/usage/configuration#branches
         * @see https://docs.github.com/en/rest/releases/releases?apiVersion=2022-11-28#get-the-latest-release
         */
        $this->dpuReleaseChannels = apply_filters(
            'update_plugins_github.com_' . $this->dpuPluginSlug . '_release_channels',
            $this->dpuReleaseChannels
        );

        if (count($this->dpuReleaseChannels) === 1 && $this->dpuReleaseChannels[0] === 'stable') {
            $url .= '/latest';
        }

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return false;
        }
        do_action('qm/debug', 'releases api call response ' . wp_remote_retrieve_body($response));
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data)) {
            return false;
        }

        return $data;
    }

    /**
     * A "viable release" for our purposes is defined as a release with at least two release assets:
     *
     * - a zip file containing the plugin
     * - a json file containing metadata about the release
     *
     * The default name for the json file is "info.json", but this can be changed using the
     * update_plugins_github.com_{pluginSlug}_info_json filter.
     *
     * @return array|false
     */
    private function dpuGetLatestViableReleases()
    {
        $releases = $this->dpuGetRemoteReleases();

        if (empty($releases)) {
            return false;
        }

        $latest = [];
        foreach ($this->dpuReleaseChannels as $channel) {
            $latest[$channel] = null;
        }

        $infoJsonFile = apply_filters(
            'update_plugins_github.com_' . $this->dpuPluginSlug . '_info_json',
            'update-info.json'
        );

        $stableAliases = apply_filters(
            'update_plugins_github.com_' . $this->dpuPluginSlug . '_stable_aliases',
            $this->dpuStableAliases
        );

        foreach ($releases as $release) {
            // skip releases with no assets
            if (empty($release['assets'])) {
                continue;
            }

            // skip draft releases
            if ($release['draft']) {
                continue;
            }

            // skip releases that don't have an info json asset
            $info = array_filter($release['assets'], function ($asset) use ($infoJsonFile) {
                return $asset['name'] === $infoJsonFile;
            });
            if (empty($info)) {
                continue;
            } else {
                $info = reset($info);
            }
            do_action('qm/debug', 'info from array filter: ' . var_export($info, true));

            // skip releases that don't have a zip asset
            $zip = array_filter($release['assets'], function ($asset) {
                return $asset['content_type'] === 'application/zip';
            });
            if (empty($zip)) {
                continue;
            } else {
                $zip = reset($zip);
            }

            // skip release channels we don't want (or don't recognize)
            $channel = $release['target_commitish'];
            if (!in_array($channel, $this->dpuReleaseChannels)) {
                // maybe it's an alias?
                if (in_array($channel, $stableAliases)) {
                    $channel = 'stable';
                } else {
                    continue;
                }
            }

            // if we already found this latest for this channel, skip it
            if (!empty($latest[$channel])) {
                continue;
            }

            // hey, looks good. let's fetch the info.json file and check it.
            $args = $this->dpuPrepRequestHeaders();
            do_action('qm/debug', 'info browser download url ' . var_export($info['browser_download_url'], true));
            $response = wp_remote_get(
                $info['browser_download_url'],
                $args
            );

            if (is_wp_error($response)) {
                // couldn't get info for this release, so skip it
                continue;
            }

            // the response _should_ be ready to decode and hand back without
            // any further processing other than assigning the browser_download_url
            do_action('qm/debug', wp_remote_retrieve_body($response));
            $data = json_decode(wp_remote_retrieve_body($response));
            if (!empty($data)) {
                /**
                 * Key word being "should" ... but list_plugin_updates() in wp-admin/update-core.php
                 * expects a mixed object/array, so we'll convert some elements to arrays.
                 *
                 * @since 1.0.0
                 */
                if ($data->package === 'browser_download_url') {
                    // TODO if this is a private repository, following the redirect link gets us
                    // an AWS signed objects.githubusercontent.com link with a 5 minute expiration.
                    // Get that link and use it instead so we don't have to manipulate the
                    // download_url() call.
                    $data->package = $zip['browser_download_url'];
                }
                // $data->icons has to be an array
                if (isset($data->icons)) {
                    $data->icons = (array) $data->icons;
                }
                // $data->banners has to be an array
                if (isset($data->banners)) {
                    $data->banners = (array) $data->banners;
                }
                // $data->sections has to be an array
                if (isset($data->sections)) {
                    $data->sections = (array) $data->sections;
                }
                $latest[$channel] = $data;
            }
        }

        return $latest;
    }
}
