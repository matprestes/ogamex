<?php

namespace Tests\Feature;

use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use OGame\Models\Resources;
use OGame\Services\SettingsService;
use Tests\AccountTestCase;

/**
 * Test that the research queue works as expected.
 */
class ResearchQueueTest extends AccountTestCase
{
    /**
     * Verify that researching energy technology works as expected.
     * @throws Exception
     */
    public function testResearchQueueEnergyTechnology(): void
    {
        // Set the universe speed to 8x and research speed to 2x for this test.
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('research_speed', 2);

        $this->planetAddResources(new Resources(0, 800, 400, 0));
        $this->planetSetObjectLevel('research_lab', 1);

        // ---
        // Step 1: Issue a request to research energy technology
        // ---
        $this->addResearchBuildRequest('energy_technology');

        // ---
        // Step 2: Verify the technology is in the research queue
        // ---
        $this->travel(1)->seconds();

        // Check if the research is in the queue and is still level 0.
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectLevelOnPage($response, 'energy_technology', 0, 'Energy technology is not still at level 0 immediately after build request issued.');

        // ---
        // Step 3: Verify the research is still in the build queue 1 minute later.
        // ---
        $this->travel(1)->minutes();

        // Check if the technology is still in the queue and is still level 0.
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectLevelOnPage($response, 'energy_technology', 0, 'Energy technology is not still at level 0 one minute after build request issued.');

        // ---
        // Step 4: Verify the research is finished 5 minutes later.
        // ---
        $this->travel(5)->minutes();

        // Check if the technology research is finished and is now level 1.
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectLevelOnPage($response, 'energy_technology', 1, 'Energy technology is not at level 1 ten minutes after build request issued.');
    }

    /**
     * Verify that researching multiple technologies works as expected.
     * @throws Exception
     */
    public function testResearchQueueMultiQueue(): void
    {
        // Set the universe speed to 8x and research speed to 2x for this test.
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('research_speed', 2);

        $this->planetAddResources(new Resources(0, 2400, 1200, 0));
        $this->planetSetObjectLevel('research_lab', 1);

        // ---
        // Step 1: Issue a request to research energy technology
        // ---
        $this->addResearchBuildRequest('energy_technology');
        $this->addResearchBuildRequest('energy_technology');

        // ---
        // Step 2: Verify the technology is in the research queue
        // ---
        // Check if the research is in the queue and is still level 0.
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectLevelOnPage($response, 'energy_technology', 0, 'Energy technology is not still at level 0 two seconds after build request issued.');

        // ---
        // Step 3: Verify that 1 research queue item is finished 2 minutes later.
        // ---
        $this->travel(2)->minutes();

        // Check if one of the research items is finished and is now level 1.
        $this->planetService->getPlayer()->updateResearchQueue();
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectLevelOnPage($response, 'energy_technology', 1, 'Energy technology is not at level one 15 minutes after build request issued.');

        // ---
        // Step 3: Verify that both research queue items are finished 30 minutes later.
        // ---
        $this->travel(30)->minutes();

        // Check if the research is finished and is now level 1.
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectLevelOnPage($response, 'energy_technology', 2, 'Energy technology is not at level two 30 minutes after build request issued.');
    }

    /**
     * Verify that when canceling a building in the build queue, the resources are refunded.
     * @throws BindingResolutionException
     * @throws Exception
     */
    public function testResearchQueueCancelRefundResources(): void
    {
        $this->planetAddResources(new Resources(0, 800, 400, 0));
        $this->planetSetObjectLevel('research_lab', 1);

        // Verify that we begin the test with expected resources.
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertResourcesOnPage($response, new Resources(0, 1300, 400, 0));

        $this->addResearchBuildRequest('energy_technology');

        $response = $this->get('/research');
        $response->assertStatus(200);

        // Assert that resources have been actually deducted
        $this->assertResourcesOnPage($response, new Resources(0, 500, 0, 0));
        // Assert that the research is in the queue
        $this->assertObjectInQueue($response, 'energy_technology', 1, 'Energy Technology level 1 is not in build queue.');

        // Extract first and second number on page which looks like this where num1/num2 are ints:
        // "cancelProduction(num1,num2,"
        $response->assertSee('cancelbuilding(');

        // Extract the first and second number from the first cancelbuilding call
        $cancelProductionCall = $response->getContent();
        if (empty($cancelProductionCall)) {
            $cancelProductionCall = '';
        }
        $cancelProductionCall = explode('onclick="cancelbuilding(', $cancelProductionCall);
        $cancelProductionCall = explode(',', $cancelProductionCall[1]);
        $number1 = (int)$cancelProductionCall[0];
        $number2 = (int)$cancelProductionCall[1];

        // Check if both numbers are integers. If not, throw an exception.
        if (empty($number1) || empty($number2)) {
            throw new BindingResolutionException('Could not extract the research queue ID from the page.');
        }

        $this->cancelResearchBuildRequest($number1, $number2);

        // ---------
        $response = $this->get('/research');
        $response->assertStatus(200);

        // Assert that resources have been refunded.
        $this->assertResourcesOnPage($response, new Resources(0, 1300, 400, 0));
    }

    /**
     * Verify that research construction time is calculated correctly (higher than 0)
     * @throws Exception
     */
    public function testResearchProductionTime(): void
    {
        // Add resources to planet to initialize planet.
        $this->planetAddResources(new Resources(400, 120, 200, 0));

        $research_time = $this->planetService->getTechnologyResearchTime('energy_technology');
        $this->assertGreaterThan(0, $research_time);
    }

    /**
     * Verify that research lab requirement is working for research objects.
     * @throws Exception
     */
    public function testResearchLabRequirement(): void
    {
        // Add required resources for research to planet
        $this->planetAddResources(new Resources(5000, 5000, 5000, 0));

        // Assert that research requirements for Energy Technology are not met as Research Lab is missing
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertRequirementsNotMet($response, 'energy_technology', 'Energy Technology research requirements met.');

        // Add Research Lab level 1 to build queue
        $this->addFacilitiesBuildRequest('research_lab');

        // Assert that research requirements for Energy Technology are not met as Research Lab is in build queue
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertRequirementsNotMet($response, 'energy_technology', 'Energy Technology research requirements met.');

        // Verify that Energy Technology can be added to research queue 2 minute later.
        $this->travel(2)->minutes();
        $this->addResearchBuildRequest('energy_technology');

        // Verify the research is finished 2 minute later.
        $this->travel(2)->minutes();

        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectLevelOnPage($response, 'energy_technology', 1, 'Energy technology is not at level one 2 minutes after build request issued.');
    }

    /**
     * Verify that ongoing upgrade of research lab prevents researching.
     * @throws Exception
     */
    public function testResearchLabUpgradingPreventsResearching(): void
    {
        // Add required resources for research to planet
        $this->planetAddResources(new Resources(5000, 5000, 5000, 0));
        $this->planetSetObjectLevel('research_lab', 1);

        // Add Research Lab level 2 to build queue
        $this->addFacilitiesBuildRequest('research_lab');
        $response = $this->get('/facilities');
        $response->assertStatus(200);
        $this->assertObjectInQueue($response, 'research_lab', 2, 'Research Lab level 2 is not in build queue');

        $this->addResearchBuildRequest('energy_technology');

        // Verify that Energy Technology is not in research queue
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectNotInQueue($response, 'energy_technology', 'Energy Technology is in research queue but should not be added.');
    }
}
