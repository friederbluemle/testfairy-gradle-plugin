<?php
	class GradleSupportMatrixTest extends PHPUnit_Framework_TestCase
	{
		private $_apiKey = "9dc08e8d93efd8622178f0c61faeaf112fbafcb4";

		// list of Android Plugins is available at http://tools.android.com/tech-docs/new-build-system
		// list of Gradle Wrappers is available at http://services.gradle.org/distributions

		public function setup() {
			parent::setup();

			// where is our development maven repository?
			$this->_projectDir = realpath(__DIR__ . "/../../../..");

			$name = $this->getName();
			$prefix = "testGradleWrapper_";
			if (substr($name, 0, strlen($prefix)) == $prefix) {
				$name = substr($name, strlen($prefix));
				$p = strpos($name, "_AndroidPlugin_");
				$wrapperVersion = substr($name, 0, $p);
				$wrapperVersion = str_replace("_", ".", $wrapperVersion);
				$pluginVersion = substr($name, $p + strlen("_AndroidPlugin_"));
				$pluginVersion = str_replace("_", ".", $pluginVersion);

				$this->tryGradle($wrapperVersion, $pluginVersion);
			}
		}

		public function tearDown() {
			parent::tearDown();
		}

		/**
		 * Changes the distributionUrl inside a gradle-wrapper.properties file
		 *
		 * @param $filename
		 * @param $wrapperVersion
		 */
		private function changeDistributionUrl($filename, $wrapperVersion) {
			$lines = file($filename, FILE_IGNORE_NEW_LINES);
			for ($i=0; $i<count($lines); $i++) {
				if (substr($lines[$i], 0, strlen("distributionUrl=")) == "distributionUrl=") {
					$lines[$i] = "distributionUrl=https\\://services.gradle.org/distributions/gradle-" . $wrapperVersion . "-all.zip";
				}
			}

			file_put_contents($filename, implode("\n", $lines));
		}

		/**
		 * Automagically add testfairy gradle plugin to the gradle build script.
		 *
		 * @param $filename   string	 path for build.gradle file being inspected
		 * @param $keystore   string	 path to keystore file used for signing app
		 */
		private function fixupBuildGradle($filename, $keystore) {
			$lines = file($filename, FILE_IGNORE_NEW_LINES);
			$out = array();
			foreach ($lines as $line) {
				/*if (preg_match("/^\\s*runProguard false/", $line)) {
					if ($useMinify) {
						$line = str_replace("runProguard false", "minifyEnabled true", $line);
					} else {
						$line = str_replace("runProguard false", "runProguard true", $line);
					}
				}*/

				if (strpos($line, "minifyEnabled false") !== FALSE) {
					$line = str_replace("minifyEnabled false", "minifyEnabled true", $line);
				}

				$out[] = $line;

				if (strpos($line, "apply plugin") !== FALSE) {
					$out[] = "apply plugin: 'testfairy'";
				}

				if (strpos($line, "minifyEnabled") !== FALSE) {
					$out[] = "            signingConfig signingConfigs.release";
					$out[] = "            zipAlignEnabled true";
				}

				if (strpos($line, "testInstrumentationRunner") !== FALSE) {
					$out[] = "    }";
					$out[] = "    signingConfigs {";
					$out[] = "        release {";
					$out[] = "            storeFile rootProject.file(\"$keystore\")";
					$out[] = "            storePassword \"swordfish\"";
					$out[] = "            keyAlias \"android_app\"";
					$out[] = "            keyPassword \"swordfish\"";
					$out[] = "        }";
					$out[] = "    }";

					/*$out[] = "    buildTypes {";
					$out[] = "        release {";
					//runProguard true
					//proguardFile getDefaultProguardFile('proguard-android.txt')
					$out[] = "        }";
					$out[] = "    }";*/

					$out[] = "    testfairyConfig {";
					//$out[] = "        serverEndpoint \"http://" . $this->_tested_server . "\"";
					$out[] = "        apiKey \"" . $this->_apiKey . "\"";
					$out[] = "        uploadProguardMapping true";
				}
			}

			file_put_contents($filename, implode("\n", $out) . "\n");
		}

		/**
		 * Automagically add testfairy gradle plugin to the gradle build script.
		 *
		 * @param $filename   string	 path for build.gradle file being inspected
		 */
		private function fixupRootBuildGradle($filename, $plugin) {
			$lines = file($filename, FILE_IGNORE_NEW_LINES);
			$out = array();
			foreach ($lines as $line) {
				if (strpos($line, "com.android.tools.build:gradle") !== FALSE) {
					$line = str_replace("2.3.2", $plugin, $line);
				}

				$out[] = $line;

				if (strpos($line, "repositories {") !== FALSE) {
					//$out[] = "        maven { url 'https://www.testfairy.com/maven' }";
					$out[] = "        maven { url 'file://" . $this->_projectDir . "/repo' }";
				}

				if (strpos($line, "dependencies {") !== FALSE) {
					$out[] = "        classpath 'com.testfairy.plugins.gradle:testfairy:1.+'";
				}
			}

			file_put_contents($filename, implode("\n", $out) . "\n");
		}

		private function getAndroidHome() {
			$home = getenv("ANDROID_HOME");
			$this->assertNotEmpty($home, "Must define ANDROID_HOME env variable");
			return $home;
		}

		private function assertZipaligned($filename) {
			$home = $this->getAndroidHome();
			exec("${home}/build-tools/25.0.3/zipalign -c 4 '$filename'", $output, $retval);
			$this->assertEquals(0, $retval, "APK file was not zipaligned");
		}

		private function assertSignedByCN($filename, $cn) {
			exec("jarsigner -certs -verbose -verify '{$filename}'", $output);
			$this->assertContains("jar verified.", $output, "Downloaded APK is not signed");
			$this->assertContains("CN=${cn},", implode("\n", $output), "Download APK is signed with another key");
		}

		private function tryGradle($wrapper, $plugin) {
			//$android = $this->getAndroidHome() . "/tools/android";
			$time = time();

			// create an empty directory first
			$TEST_DIR="/tmp/gradle-test-$time";
//			system("rm -rf $TEST_DIR");
			@mkdir($TEST_DIR);
			//exec("$android create project -v $plugin -n GradleTest -t android-8 -p $TEST_DIR -g -k com.testfairy.tests.gradle -a MainActivity", $output);
			exec("cp -a gradle-test/* $TEST_DIR");

			// create a certificate for this
			$dname = "CN=${time},OU=organizational_unit,O=organization,L=locality,S=state,C=US";
			system("keytool -genkey -keystore ${TEST_DIR}/random.keystore -alias android_app -keyalg RSA -keysize 2048 -validity 3650 -keypass 'swordfish' -storepass 'swordfish' -dname '$dname' 2>&1");

			$this->changeDistributionUrl("$TEST_DIR/gradle/wrapper/gradle-wrapper.properties", $wrapper);
			$this->fixupRootBuildGradle("$TEST_DIR/build.gradle", $plugin);
			$this->fixupBuildGradle("$TEST_DIR/app/build.gradle", "random.keystore");

			// check plugin loaded successfully
			exec("cd $TEST_DIR; ./gradlew tasks", $output);
			$this->assertContains("testfairyRelease - Uploads the Release build to TestFairy", $output);
			$this->assertContains("testfairyDebug - Uploads the Debug build to TestFairy", $output);

			// try testfairyRelease task
			$output = array();
			exec("cd $TEST_DIR; ./gradlew testfairyRelease --debug", $output);

			// make sure it uploaded successfully to testfairy
			$found = false;
			foreach ($output as $line) {
				if (preg_match("/Successfully uploaded to TestFairy, build is available at:/", $line)) {
					$found = true;
					break;
				}
			}

			$this->assertTrue($found, "Compilation failed");

			$signedUrl = null;
			foreach ($output as $line) {
				if (preg_match("/Signed instrumented file is available at: (.+)/", $line, $match)) {
					$signedUrl = $match[1];
					break;
				}
			}

			$this->assertNotNull($signedUrl, "Could not find signed instrumented file url in debug logs");

			$apkFilePath = "${TEST_DIR}/signed.apk";

			// fetch signed apk
			copy($signedUrl, $apkFilePath);

			// make sure app is signed
			$this->assertSignedByCN($apkFilePath, $time);
			$this->assertZipAligned($apkFilePath);
		}

		// Gradle Wrapper 1.10
		/*public function testGradleWrapper_1_10_AndroidPlugin_0_10_0() { }
		public function testGradleWrapper_1_10_AndroidPlugin_0_10_1() { }
		public function testGradleWrapper_1_10_AndroidPlugin_0_10_2() { }
		public function testGradleWrapper_1_10_AndroidPlugin_0_10_4() { }
		public function testGradleWrapper_1_10_AndroidPlugin_0_11_0() { }
		public function testGradleWrapper_1_10_AndroidPlugin_0_12_0() { }
		public function testGradleWrapper_1_10_AndroidPlugin_0_12_1() { }
		public function testGradleWrapper_1_10_AndroidPlugin_0_12_2() { }

		// Gradle Wrapper 1.11
		public function testGradleWrapper_1_11_AndroidPlugin_0_10_0() { }
		public function testGradleWrapper_1_11_AndroidPlugin_0_10_1() { }
		public function testGradleWrapper_1_11_AndroidPlugin_0_10_2() { }
		public function testGradleWrapper_1_11_AndroidPlugin_0_10_4() { }
		public function testGradleWrapper_1_11_AndroidPlugin_0_11_0() { }
		public function testGradleWrapper_1_11_AndroidPlugin_0_12_0() { }
		public function testGradleWrapper_1_11_AndroidPlugin_0_12_1() { }
		public function testGradleWrapper_1_11_AndroidPlugin_0_12_2() { }

		// Gradle Wrapper 1.12
		public function testGradleWrapper_1_12_AndroidPlugin_0_10_0() { }
		public function testGradleWrapper_1_12_AndroidPlugin_0_10_1() { }
		public function testGradleWrapper_1_12_AndroidPlugin_0_10_2() { }
		public function testGradleWrapper_1_12_AndroidPlugin_0_10_4() { }
		public function testGradleWrapper_1_12_AndroidPlugin_0_11_0() { }
		public function testGradleWrapper_1_12_AndroidPlugin_0_12_0() { }
		public function testGradleWrapper_1_12_AndroidPlugin_0_12_1() { }
		public function testGradleWrapper_1_12_AndroidPlugin_0_12_2() { }

		// Gradle Wrapper 2.1
		public function testGradleWrapper_2_1_AndroidPlugin_0_13_0() { }
		public function testGradleWrapper_2_1_AndroidPlugin_0_13_1() { }
		public function testGradleWrapper_2_1_AndroidPlugin_0_13_2() { }
		public function testGradleWrapper_2_1_AndroidPlugin_0_13_3() { }
		public function testGradleWrapper_2_1_AndroidPlugin_0_14_0() { }
		public function testGradleWrapper_2_1_AndroidPlugin_0_14_1() { }
		public function testGradleWrapper_2_1_AndroidPlugin_0_14_2() { }
		public function testGradleWrapper_2_1_AndroidPlugin_0_14_3() { }
		public function testGradleWrapper_2_1_AndroidPlugin_0_14_4() { }

		// Gradle Wrapper 2.2
		public function testGradleWrapper_2_2_AndroidPlugin_0_14_0() { }
		public function testGradleWrapper_2_2_AndroidPlugin_0_14_1() { }
		public function testGradleWrapper_2_2_AndroidPlugin_0_14_2() { }
		public function testGradleWrapper_2_2_AndroidPlugin_0_14_3() { }
		public function testGradleWrapper_2_2_AndroidPlugin_0_14_4() { }
		public function testGradleWrapper_2_2_AndroidPlugin_1_0_0() { }
		public function testGradleWrapper_2_2_AndroidPlugin_1_0_1() { }

		// Gradle Wrapper 2.14
		public function testGradleWrapper_2_14_AndroidPlugin_1_5_0() { }*/

		// Gradle Wrapper 2.14.1
		//public function testGradleWrapper_2_14_1_AndroidPlugin_2_1_3() { }
		public function testGradleWrapper_2_14_1_AndroidPlugin_2_2_0() { }

		// Gradle Wrapper 3.2.1
		/*public function testGradleWrapper_3_2_1_AndroidPlugin_2_1_3() { }

		public function testGradleWrapper_3_3_AndroidPlugin_2_3_0() { }
		public function testGradleWrapper_3_3_AndroidPlugin_2_3_2() { }*/
	}
?>
