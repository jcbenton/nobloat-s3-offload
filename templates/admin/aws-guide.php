<?php
/**
 * AWS setup guide template.
 *
 * @package Nobloat_S3_Offload
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
?>
<div id="nbs3">
	<div class="wrap">
		<div class="nbs3-documentation">

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'AWS S3 Setup Guide', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<h3><?php esc_html_e( 'Step 1: Create an S3 Bucket', 'nobloat-s3-offload' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Go to the AWS S3 Console', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Click "Create bucket"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Enter a unique bucket name (e.g., mysite-media)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Select your preferred AWS Region', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Under "Object Ownership", select "ACLs disabled (recommended)"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Under "Block Public Access", uncheck "Block all public access" (you\'ll control access via bucket policy)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Click "Create bucket"', 'nobloat-s3-offload' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Step 2: Configure Bucket Policy', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'Go to your bucket > Permissions > Bucket policy, and add:', 'nobloat-s3-offload' ); ?></p>
					<pre><code>{
	"Version": "2012-10-17",
	"Statement": [
		{
			"Sid": "PublicRead",
			"Effect": "Allow",
			"Principal": "*",
			"Action": "s3:GetObject",
			"Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*"
		}
	]
}</code></pre>
					<p><?php esc_html_e( 'Replace YOUR-BUCKET-NAME with your actual bucket name.', 'nobloat-s3-offload' ); ?></p>

					<h3><?php esc_html_e( 'Step 3: Configure CORS', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'Go to your bucket > Permissions > Cross-origin resource sharing (CORS), and add:', 'nobloat-s3-offload' ); ?></p>
					<pre><code>[
	{
		"AllowedHeaders": ["*"],
		"AllowedMethods": ["GET", "HEAD"],
		"AllowedOrigins": ["*"],
		"ExposeHeaders": [],
		"MaxAgeSeconds": 3600
	}
]</code></pre>
					<p><?php esc_html_e( 'For production, you can restrict AllowedOrigins to your domain(s):', 'nobloat-s3-offload' ); ?></p>
					<pre><code>"AllowedOrigins": ["https://yourdomain.com", "https://www.yourdomain.com"]</code></pre>

					<h3><?php esc_html_e( 'Step 4: Create IAM User', 'nobloat-s3-offload' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Go to IAM Console > Users > Create user', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Enter a username (e.g., wordpress-s3-offload)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Select "Attach policies directly"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Click "Create policy" and use this JSON:', 'nobloat-s3-offload' ); ?></li>
					</ol>
					<pre><code>{
	"Version": "2012-10-17",
	"Statement": [
		{
			"Effect": "Allow",
			"Action": [
				"s3:PutObject",
				"s3:GetObject",
				"s3:DeleteObject",
				"s3:HeadObject",
				"s3:ListBucket"
			],
			"Resource": [
				"arn:aws:s3:::YOUR-BUCKET-NAME",
				"arn:aws:s3:::YOUR-BUCKET-NAME/*"
			]
		}
	]
}</code></pre>
					<ol start="5">
						<li><?php esc_html_e( 'Attach the policy to the user and create the user', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Go to the user > Security credentials > Create access key', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Select "Application running outside AWS"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Save the Access Key ID and Secret Access Key securely', 'nobloat-s3-offload' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Step 5: Store Credentials Securely (Recommended)', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'For better security, define your credentials in wp-config.php instead of the database:', 'nobloat-s3-offload' ); ?></p>
					<pre><code>// AWS S3 Credentials
define( 'NBS3_ACCESS_KEY_ID', 'your-access-key-id' );
define( 'NBS3_SECRET_ACCESS_KEY', 'your-secret-access-key' );
define( 'NBS3_BUCKET', 'your-bucket-name' );
define( 'NBS3_REGION', 'us-east-1' );

// Optional: Custom endpoint for S3-compatible services
// define( 'NBS3_ENDPOINT', 'https://s3.example.com' );

// Optional: CloudFront or custom CDN domain
// define( 'NBS3_CDN_DOMAIN', 'https://d1234abcd.cloudfront.net' );</code></pre>
					<p><?php esc_html_e( 'When credentials are defined in wp-config.php, they take precedence over the plugin settings and cannot be changed from the admin interface.', 'nobloat-s3-offload' ); ?></p>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'CloudFront Setup Guide', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'CloudFront is a CDN that caches your S3 files at edge locations worldwide, improving load times for visitors.', 'nobloat-s3-offload' ); ?></p>

					<div class="nbs3-notice nbs3-notice-info">
						<p><strong><?php esc_html_e( 'Note:', 'nobloat-s3-offload' ); ?></strong> <?php esc_html_e( 'If you use CloudFront with Origin Access Control (OAC), you can enable "Block all public access" on your S3 bucket. This ensures files are only accessible via CloudFront, not directly from S3. The guide below uses OAC which is the recommended approach.', 'nobloat-s3-offload' ); ?></p>
					</div>

					<h3><?php esc_html_e( 'Step 1: Create a CloudFront Distribution', 'nobloat-s3-offload' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Go to CloudFront Console > Create distribution', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Origin domain: Select your S3 bucket from the dropdown', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Origin access: Select "Origin access control settings (recommended)"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Click "Create new OAC" and use the default settings', 'nobloat-s3-offload' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Step 2: Configure Cache Behavior', 'nobloat-s3-offload' ); ?></h3>
					<ul>
						<li><?php esc_html_e( 'Viewer protocol policy: "Redirect HTTP to HTTPS"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Allowed HTTP methods: "GET, HEAD"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Cache policy: "CachingOptimized" (recommended)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Origin request policy: "CORS-S3Origin" (for font/asset CORS support)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Response headers policy: "SimpleCORS" (optional, for additional CORS headers)', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'Step 3: Update S3 Bucket Policy for CloudFront', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'After creating the distribution, CloudFront will prompt you to update your bucket policy. Add this statement:', 'nobloat-s3-offload' ); ?></p>
					<pre><code>{
	"Version": "2012-10-17",
	"Statement": [
		{
			"Sid": "AllowCloudFrontServicePrincipal",
			"Effect": "Allow",
			"Principal": {
				"Service": "cloudfront.amazonaws.com"
			},
			"Action": "s3:GetObject",
			"Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*",
			"Condition": {
				"ArnLike": {
					"AWS:SourceArn": "arn:aws:cloudfront::YOUR-ACCOUNT-ID:distribution/YOUR-DISTRIBUTION-ID"
				}
			}
		}
	]
}</code></pre>
					<p><?php esc_html_e( 'Replace YOUR-BUCKET-NAME, YOUR-ACCOUNT-ID, and YOUR-DISTRIBUTION-ID with your actual values.', 'nobloat-s3-offload' ); ?></p>

					<h3><?php esc_html_e( 'Step 4: Configure the Plugin', 'nobloat-s3-offload' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Wait for the distribution to deploy (Status: "Enabled")', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Copy the distribution domain name (e.g., d1234abcd.cloudfront.net)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'In the plugin settings, enter the CloudFront URL in the "CloudFront or Custom Domain (CDN)" field', 'nobloat-s3-offload' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Optional: Custom Domain with SSL', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'To use a custom domain (e.g., cdn.yourdomain.com):', 'nobloat-s3-offload' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'Request an SSL certificate in AWS Certificate Manager (ACM) in us-east-1 region', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Add your custom domain as an "Alternate domain name (CNAME)" in CloudFront', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Select your ACM certificate', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Create a CNAME DNS record pointing your subdomain to the CloudFront domain', 'nobloat-s3-offload' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Cache Invalidation', 'nobloat-s3-offload' ); ?></h3>
					<p><?php esc_html_e( 'If you update files and they\'re not showing, you may need to invalidate the CloudFront cache:', 'nobloat-s3-offload' ); ?></p>
					<ol>
						<li><?php esc_html_e( 'Go to your CloudFront distribution > Invalidations', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Create invalidation with path: /*', 'nobloat-s3-offload' ); ?></li>
					</ol>
					<p><?php esc_html_e( 'Or via AWS CLI:', 'nobloat-s3-offload' ); ?></p>
					<pre><code>aws cloudfront create-invalidation --distribution-id YOUR-DISTRIBUTION-ID --paths "/*"</code></pre>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Combined Bucket Policy', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<p><?php esc_html_e( 'If using CloudFront with OAC, you can combine public read and CloudFront access in one policy:', 'nobloat-s3-offload' ); ?></p>
					<pre><code>{
	"Version": "2012-10-17",
	"Statement": [
		{
			"Sid": "PublicRead",
			"Effect": "Allow",
			"Principal": "*",
			"Action": "s3:GetObject",
			"Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*"
		},
		{
			"Sid": "AllowCloudFrontServicePrincipal",
			"Effect": "Allow",
			"Principal": {
				"Service": "cloudfront.amazonaws.com"
			},
			"Action": "s3:GetObject",
			"Resource": "arn:aws:s3:::YOUR-BUCKET-NAME/*",
			"Condition": {
				"ArnLike": {
					"AWS:SourceArn": "arn:aws:cloudfront::YOUR-ACCOUNT-ID:distribution/YOUR-DISTRIBUTION-ID"
				}
			}
		}
	]
}</code></pre>
					<p><?php esc_html_e( 'Note: The PublicRead statement allows direct S3 access as a fallback. If you want CloudFront-only access, remove the PublicRead statement and enable "Block all public access" on the bucket.', 'nobloat-s3-offload' ); ?></p>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Common AWS Regions', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<table class="nbs3-docs-table">
						<tr>
							<th><?php esc_html_e( 'Region Code', 'nobloat-s3-offload' ); ?></th>
							<th><?php esc_html_e( 'Region Name', 'nobloat-s3-offload' ); ?></th>
						</tr>
						<tr>
							<td>us-east-1</td>
							<td><?php esc_html_e( 'US East (N. Virginia)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>us-east-2</td>
							<td><?php esc_html_e( 'US East (Ohio)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>us-west-1</td>
							<td><?php esc_html_e( 'US West (N. California)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>us-west-2</td>
							<td><?php esc_html_e( 'US West (Oregon)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>eu-west-1</td>
							<td><?php esc_html_e( 'Europe (Ireland)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>eu-west-2</td>
							<td><?php esc_html_e( 'Europe (London)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>eu-central-1</td>
							<td><?php esc_html_e( 'Europe (Frankfurt)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>ap-southeast-1</td>
							<td><?php esc_html_e( 'Asia Pacific (Singapore)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>ap-southeast-2</td>
							<td><?php esc_html_e( 'Asia Pacific (Sydney)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>ap-northeast-1</td>
							<td><?php esc_html_e( 'Asia Pacific (Tokyo)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>ap-south-1</td>
							<td><?php esc_html_e( 'Asia Pacific (Mumbai)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>sa-east-1</td>
							<td><?php esc_html_e( 'South America (São Paulo)', 'nobloat-s3-offload' ); ?></td>
						</tr>
						<tr>
							<td>ca-central-1</td>
							<td><?php esc_html_e( 'Canada (Central)', 'nobloat-s3-offload' ); ?></td>
						</tr>
					</table>
					<p><?php esc_html_e( 'Choose a region closest to your primary audience for best performance.', 'nobloat-s3-offload' ); ?></p>
				</div>
			</div>

			<div class="nbs3-section">
				<div class="nbs3-section-header">
					<h2><?php esc_html_e( 'Troubleshooting', 'nobloat-s3-offload' ); ?></h2>
				</div>
				<div class="nbs3-section-content">
					<h3><?php esc_html_e( 'Common Issues', 'nobloat-s3-offload' ); ?></h3>

					<h4><?php esc_html_e( '"Access Denied" when uploading files', 'nobloat-s3-offload' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Verify your IAM user has s3:PutObject permission', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Check that the bucket name in the IAM policy matches your actual bucket', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Ensure the Access Key ID and Secret Access Key are correct', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h4><?php esc_html_e( 'Images not displaying after offload', 'nobloat-s3-offload' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Verify the bucket policy allows public read access (s3:GetObject)', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'If using CloudFront, ensure the distribution is deployed and the bucket policy includes CloudFront access', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Check that "Block all public access" is disabled on the bucket (unless using CloudFront OAC)', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h4><?php esc_html_e( 'CORS errors with fonts or assets', 'nobloat-s3-offload' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Ensure CORS is configured on the S3 bucket', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'If using CloudFront, set the Origin request policy to "CORS-S3Origin"', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'For stricter security, set AllowedOrigins to your specific domain(s)', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h4><?php esc_html_e( '"Invalid bucket name" or connection errors', 'nobloat-s3-offload' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'Bucket names must be globally unique and follow AWS naming rules', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Verify the region matches where your bucket was created', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Check for typos in the bucket name, region, or credentials', 'nobloat-s3-offload' ); ?></li>
					</ul>

					<h4><?php esc_html_e( 'Files not updating after edit', 'nobloat-s3-offload' ); ?></h4>
					<ul>
						<li><?php esc_html_e( 'If using CloudFront, create an invalidation for the affected paths', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Clear your browser cache and any WordPress caching plugins', 'nobloat-s3-offload' ); ?></li>
						<li><?php esc_html_e( 'Consider enabling "Object Versioning" in the plugin settings to bypass caching issues', 'nobloat-s3-offload' ); ?></li>
					</ul>
				</div>
			</div>

		</div>
	</div>
</div>
