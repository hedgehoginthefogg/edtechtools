<?php
// --- Configuration ---
// Verify the correct Gemini API endpoint for text generation with your chosen model
// Using gemini-1.5-flash model. Check Google AI documentation for the latest stable version/endpoint.
$geminiApiEndpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

// --- Set Output Content Type ---
// This should be set early, but might be overridden later if sending plain text error
header('Content-Type: application/json');

// --- Basic Request Validation ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    // Ensure error messages are JSON encoded
    echo json_encode(['error' => 'Invalid request method. Only POST is allowed.']);
    exit;
}

// Read raw input and decode JSON
$input = json_decode(file_get_contents('php://input'), true);

// Check for JSON decoding errors or missing required fields
if (json_last_error() !== JSON_ERROR_NONE || !isset($input['quiz_text']) || !isset($input['api_key'])) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid JSON input or missing required fields (quiz_text, api_key).']);
    exit;
}

// Extract variables safely
$quizText = $input['quiz_text'] ?? ''; // Use null coalescing operator for safety
$apiKey = $input['api_key'] ?? '';   // User's API Key - DO NOT LOG OR STORE

// Basic validation if needed (e.g., check if empty after extraction)
if (empty($quizText) || empty($apiKey)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Empty quiz_text or api_key received.']);
    exit;
}


// --- Build Gemini Prompt ---
// (Using the same detailed prompt structure as before)
$prompt = <<<PROMPT
Parse the following text which contains multiple-choice questions.
For each question, identify:
1. The question stem (the main question text).
2. The answer options (as a JSON list of strings).
3. The correct answer (the exact string of the correct option). Assume the correct answer is indicated by an asterisk (*) before it, or specified clearly (e.g., "Correct answer: C").

Output the result ONLY as a JSON array of objects. Each object MUST have keys: "question_stem", "options", and "correct_answer".
Do NOT include numbering (like 1., a)) within the question_stem or options unless it's part of the text itself. Remove the asterisk or any other indicator from the correct answer option string when listing it under "options". The value for "correct_answer" should be the exact text of the choice identified as correct.

Example Input:
1. What is the capital of France?
a) London
b) Berlin
*c) Paris
d) Madrid

Example JSON Object Output:
{
  "question_stem": "What is the capital of France?",
  "options": ["London", "Berlin", "Paris", "Madrid"],
  "correct_answer": "Paris"
}

Example Input 2:
Question 2) Which planet is known as the Red Planet?
A. Earth
B. Mars *
C. Jupiter
D. Saturn

Example JSON Object Output 2:
{
  "question_stem": "Which planet is known as the Red Planet?",
  "options": ["Earth", "Mars", "Jupiter", "Saturn"],
  "correct_answer": "Mars"
}

Now parse the following text:
--- START QUIZ TEXT ---
{$quizText}
--- END QUIZ TEXT ---

Return ONLY the JSON array. Do not include any explanatory text, markdown formatting like ```json, or anything else before or after the JSON array. Just the raw JSON.
PROMPT;


// --- Prepare Gemini API Request Body ---
// Note: Structure follows Google's v1beta API documentation
$requestBodyPayload = [
    'contents' => [[
        'parts' => [[
            'text' => $prompt
        ]]
    ]]
    // Add optional 'generationConfig' or 'safetySettings' here if needed
];

$requestBodyJson = json_encode($requestBodyPayload);

// Check for encoding errors
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    error_log("PHP failed to encode request body for Gemini API: " . json_last_error_msg()); // Log actual error
    echo json_encode(['error' => 'Internal Server Error: Failed to encode request for Gemini API.']);
    exit;
}

// --- Make API Call using cURL ---
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $geminiApiEndpoint); // Use the base endpoint URL
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response as string
curl_setopt($ch, CURLOPT_POST, true);           // Set method to POST
curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBodyJson); // Set request body
curl_setopt($ch, CURLOPT_HTTPHEADER, [          // Set headers
    'Content-Type: application/json',
    'Accept: application/json',
    'x-goog-api-key: ' . $apiKey // Securely send the API key as a header
]);
// Optional: Add timeout
curl_setopt($ch, CURLOPT_TIMEOUT, 45); // 45 seconds timeout (Gemini can take time)
// Optional: Disable SSL verification if facing issues on server (NOT RECOMMENDED FOR PRODUCTION)
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);


$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

// --- Handle cURL or API Response ---
if ($curlError) {
    http_response_code(502); // Bad Gateway (error talking to upstream server)
    error_log("Proxy cURL Error to Gemini: " . $curlError); // Log actual error
    echo json_encode(['error' => 'Failed to connect to Gemini API. Please check server logs or try again later.']);
    exit;
}

if ($httpcode !== 200) {
    // Attempt to decode Gemini's error response for better feedback
    $errorData = json_decode($response, true);
    $errorMessage = 'Gemini API returned an error.';
    if (json_last_error() === JSON_ERROR_NONE && isset($errorData['error']['message'])) {
        $errorMessage = 'Gemini API Error: ' . $errorData['error']['message'];
    }
    // Log the detailed error server-side
    error_log("Gemini API Error - HTTP Status: " . $httpcode . " Response: " . $response);
    // Send back a user-friendly error
    http_response_code(502); // Bad Gateway or use $httpcode if appropriate
    echo json_encode(['error' => $errorMessage, 'status_code' => $httpcode]);
    exit;
}

// --- Process Successful Gemini Response ---
$geminiResponseData = json_decode($response, true);
$jsonLastError = json_last_error();

// Check for safety blocks first
if (isset($geminiResponseData['promptFeedback']['blockReason'])) {
    http_response_code(400); // Bad Request (due to content)
    echo json_encode([
        'error' => 'Content blocked by Gemini API safety filters.',
        'reason' => $geminiResponseData['promptFeedback']['blockReason'] ?? 'Unknown'
    ]);
}
// Check if response decoded and expected content path exists
else if ($jsonLastError === JSON_ERROR_NONE && isset($geminiResponseData['candidates'][0]['content']['parts'][0]['text']))
{
    $rawJsonText = $geminiResponseData['candidates'][0]['content']['parts'][0]['text'];

    // --- Attempt to strip markdown fences ---
    $strippedJsonText = $rawJsonText; // Default to raw text
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $rawJsonText, $matches)) {
        // If fences found, use the captured content inside
        if (isset($matches[1])) {
            $strippedJsonText = trim($matches[1]);
        }
    }
    // --- End stripping logic ---

    // Validate if the (potentially stripped) text IS valid JSON before sending
    json_decode($strippedJsonText);
    if (json_last_error() === JSON_ERROR_NONE) {
         // It's valid JSON (or seems to be), send it back
         header('Content-Type: application/json'); // Ensure header is set
         echo $strippedJsonText;
    } else {
         // The text from Gemini (even after stripping) isn't valid JSON
         http_response_code(502); // Bad Gateway - Proxy received invalid response
         error_log("Gemini response payload was not valid JSON after potential stripping. Raw text snippet: " . substr($rawJsonText, 0, 500)); // Log error server-side
         echo json_encode([
             'error' => 'Received unusable content from Gemini API after processing. Could not parse internal JSON.',
             'received_text_snippet' => substr($rawJsonText, 0, 200) // Send back a snippet safely
         ]);
    }
}
else {
    // If the response structure from Gemini is unexpected or failed basic JSON decode
    http_response_code(500); // Or 502 Bad Gateway might be better
    error_log("Received unexpected response structure or invalid JSON from Gemini API. HTTP Code: " . $httpcode . " Response Snippet: " . substr($response, 0, 500)); // Log error
    echo json_encode([
        'error' => 'Received unexpected response structure or invalid JSON from Gemini API.',
        'received_snippet' => substr($response, 0, 200) // Send back a snippet safely
    ]);
}

exit; // Ensure no extra output

?>